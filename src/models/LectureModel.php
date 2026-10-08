<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Models;

class LectureModel
{
    public static function getByExternalId(string $externalId): ?object
    {
        $output = executeQuery('simple_video_learning.getLectureByExternalId', ['external_id' => $externalId]);
        return ($output->toBool() && !empty($output->data)) ? $output->data : null;
    }

    public static function getByDocument(int $documentSrl): ?object
    {
        $output = executeQuery('simple_video_learning.getLectureByDocument', ['document_srl' => $documentSrl]);
        return ($output->toBool() && !empty($output->data)) ? $output->data : null;
    }

    /** @return array<int, object> */
    public static function getByModule(int $moduleSrl): array
    {
        $output = executeQueryArray('simple_video_learning.getLecturesByModule', ['module_srl' => $moduleSrl]);
        if (!$output->toBool() || empty($output->data)) {
            return [];
        }
        return is_array($output->data) ? $output->data : [$output->data];
    }

    /** @return array<int, object> */
    public static function getByCourse(int $courseSrl): array
    {
        $output = executeQueryArray('simple_video_learning.getLecturesByCourse', ['course_srl' => $courseSrl]);
        if (!$output->toBool() || empty($output->data)) {
            return [];
        }
        $rows = is_array($output->data) ? $output->data : [$output->data];
        usort($rows, static function (object $a, object $b): int {
            $week = (int)($a->week_no ?? 1) <=> (int)($b->week_no ?? 1);
            return $week !== 0 ? $week : ((int)($a->sort_order ?? 0) <=> (int)($b->sort_order ?? 0));
        });
        return $rows;
    }

    /** @return array<int, object> */
    public static function getByCourseSafely(int $courseSrl): array
    {
        try {
            $db = \DB::getInstance();
            if (!$db->isTableExists('simple_video_learning_lectures') || !$db->isColumnExists('simple_video_learning_lectures', 'course_srl')) {
                error_log('[simple_video_learning] lecture course mapping schema is not ready');
                return [];
            }

            $output = executeQueryArray('simple_video_learning.getLecturesByCourse', ['course_srl' => $courseSrl]);
            if (!$output->toBool()) {
                error_log('[simple_video_learning] getLecturesByCourse failed: ' . (string)$output->getMessage());
                return [];
            }
            if (empty($output->data)) {
                return [];
            }

            $rows = is_array($output->data) ? $output->data : [$output->data];
            usort($rows, static function (object $a, object $b): int {
                $week = (int)($a->week_no ?? 1) <=> (int)($b->week_no ?? 1);
                return $week !== 0 ? $week : ((int)($a->sort_order ?? 0) <=> (int)($b->sort_order ?? 0));
            });
            return $rows;
        } catch (\Throwable $e) {
            error_log('[simple_video_learning] getByCourseSafely exception: ' . $e->getMessage());
            return [];
        }
    }

    /** @return array<int, object> */
    public static function getAll(): array
    {
        $output = executeQueryArray('simple_video_learning.getAllLectures');
        if (!$output->toBool() || empty($output->data)) {
            return [];
        }
        return is_array($output->data) ? $output->data : [$output->data];
    }

    public static function upsert(array $data): \BaseObject
    {
        $existing = self::getByExternalId((string)$data['external_id']);
        $now = date('YmdHis');

        $data['module_srl'] = (int)($data['module_srl'] ?? ($existing->module_srl ?? 0));
        $data['course_srl'] = (int)($data['course_srl'] ?? ($existing->course_srl ?? 0));
        $data['week_no'] = max(1, (int)($data['week_no'] ?? ($existing->week_no ?? 1)));
        $data['sort_order'] = max(0, (int)($data['sort_order'] ?? ($existing->sort_order ?? 0)));
        $data['uploader_srl'] = max(0, (int)($data['uploader_srl'] ?? ($existing->uploader_srl ?? 0)));

        if ($existing) {
            $data['last_update'] = $now;
            return executeQuery('simple_video_learning.updateLecture', $data);
        }

        $data['lecture_srl'] = getNextSequence();
        $data['regdate'] = $now;
        $data['last_update'] = $now;
        return executeQuery('simple_video_learning.insertLecture', $data);
    }

    public static function assignCourse(int $documentSrl, int $courseSrl, int $weekNo, int $sortOrder = 0): \BaseObject
    {
        $lecture = self::getByDocument($documentSrl);
        if (!$lecture) {
            return new \BaseObject(-1, 'Lecture not found.');
        }

        return self::upsert([
            'external_id' => (string)$lecture->external_id,
            'module_srl' => (int)$lecture->module_srl,
            'course_srl' => $courseSrl,
            'week_no' => max(1, $weekNo),
            'sort_order' => max(0, $sortOrder),
            'uploader_srl' => (int)($lecture->uploader_srl ?? 0),
            'document_srl' => (int)$lecture->document_srl,
            'video_url' => (string)$lecture->video_url,
            'duration' => (int)$lecture->duration,
            'completion_threshold' => (int)$lecture->completion_threshold,
        ]);
    }
}
