<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Models;

class ProgressModel
{
    private const MAX_HEARTBEAT_GAP = 10;

    public static function get(int $documentSrl, int $memberSrl): ?object
    {
        $key = self::key($documentSrl, $memberSrl);
        $output = executeQuery('simple_video_learning.getProgress', ['progress_key' => $key]);
        return ($output->toBool() && !empty($output->data)) ? $output->data : null;
    }

    public static function record(
        int $documentSrl,
        int $memberSrl,
        int $currentTime,
        int $duration,
        bool $playing,
        int $threshold
    ): array {
        $currentTime = max(0, min($currentTime, max(0, $duration)));
        $threshold = max(1, min(100, $threshold));
        $now = date('YmdHis');
        $existing = self::get($documentSrl, $memberSrl);

        if (!$existing) {
            $ranges = [];
            $lastPosition = 0;
            $elapsed = 0;
        } else {
            $ranges = self::decodeRanges((string)$existing->watched_ranges);
            $lastPosition = max(0, (int)$existing->last_position);
            $elapsed = min(self::MAX_HEARTBEAT_GAP, self::elapsedSeconds((string)$existing->last_heartbeat));
        }

        if (!$existing) {
            $allowedPosition = 0;
        } elseif ($playing) {
            // A request must not mint watch time: even repeated requests in the
            // same second share the server's elapsed-time budget.
            $maxForward = $lastPosition + $elapsed;
            $allowedPosition = min($currentTime, $maxForward);
        } else {
            $existingFrontier = max(0, (int)$existing->frontier);
            $allowedPosition = min($currentTime, $existingFrontier);
        }

        if ($playing && $allowedPosition > $lastPosition) {
            $ranges[] = [$lastPosition, $allowedPosition];
        }

        $ranges = self::mergeRanges($ranges, $duration);
        $watchedSeconds = self::sumRanges($ranges);
        $frontier = self::contiguousFrontier($ranges);
        $completed = ($duration > 0 && ($watchedSeconds / $duration) * 100 >= $threshold) ? 'Y' : 'N';

        $payload = [
            'progress_key' => self::key($documentSrl, $memberSrl),
            'document_srl' => $documentSrl,
            'member_srl' => $memberSrl,
            'watched_ranges' => json_encode($ranges, JSON_UNESCAPED_SLASHES),
            'watched_seconds' => $watchedSeconds,
            'frontier' => $frontier,
            'last_position' => $allowedPosition,
            'completed' => $completed,
            'last_heartbeat' => $now,
        ];

        if ($existing) {
            $output = executeQuery('simple_video_learning.updateProgress', $payload);
        } else {
            $payload['progress_srl'] = getNextSequence();
            $payload['regdate'] = $now;
            $output = executeQuery('simple_video_learning.insertProgress', $payload);
        }

        if (!$output->toBool()) {
            throw new \RuntimeException($output->getMessage());
        }

        return [
            'watched_seconds' => $watchedSeconds,
            'frontier' => $frontier,
            'position' => $allowedPosition,
            'completed' => $completed === 'Y',
            'percentage' => $duration > 0 ? round(($watchedSeconds / $duration) * 100, 2) : 0,
            'ranges' => $ranges,
        ];
    }

    private static function key(int $documentSrl, int $memberSrl): string
    {
        return $documentSrl . ':' . $memberSrl;
    }

    private static function decodeRanges(string $json): array
    {
        $ranges = json_decode($json, true);
        return is_array($ranges) ? $ranges : [];
    }

    private static function mergeRanges(array $ranges, int $duration): array
    {
        $normalized = [];
        foreach ($ranges as $range) {
            if (!is_array($range) || count($range) < 2) {
                continue;
            }
            $start = max(0, min((int)$range[0], $duration));
            $end = max(0, min((int)$range[1], $duration));
            if ($end > $start) {
                $normalized[] = [$start, $end];
            }
        }

        usort($normalized, static function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $merged = [];
        foreach ($normalized as $range) {
            if (!$merged) {
                $merged[] = $range;
                continue;
            }
            $lastIndex = count($merged) - 1;
            if ($range[0] <= $merged[$lastIndex][1] + 1) {
                $merged[$lastIndex][1] = max($merged[$lastIndex][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }
        return $merged;
    }

    private static function sumRanges(array $ranges): int
    {
        $total = 0;
        foreach ($ranges as $range) {
            $total += max(0, (int)$range[1] - (int)$range[0]);
        }
        return $total;
    }

    private static function contiguousFrontier(array $ranges): int
    {
        $frontier = 0;
        foreach ($ranges as $range) {
            if ((int)$range[0] > $frontier + 1) {
                break;
            }
            $frontier = max($frontier, (int)$range[1]);
        }
        return $frontier;
    }

    private static function elapsedSeconds(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $dt = \DateTime::createFromFormat('YmdHis', $value);
        if (!$dt) {
            return 0;
        }
        return max(0, time() - $dt->getTimestamp());
    }
}
