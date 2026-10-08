<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Models;

class EnrollmentModel
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const SUSPENDED = 'suspended';

    public static function get(int $courseSrl, int $memberSrl): ?object
    {
        if (!\DB::getInstance()->isTableExists('simple_video_learning_enrollments')) {
            return null;
        }
        $output = executeQuery('simple_video_learning.getEnrollment', [
            'enrollment_key' => self::key($courseSrl, $memberSrl),
        ]);
        return ($output->toBool() && !empty($output->data)) ? $output->data : null;
    }

    public static function getStatus(int $courseSrl, int $memberSrl): ?string
    {
        $row = self::get($courseSrl, $memberSrl);
        if (!$row || (string)($row->active ?? 'N') !== 'Y') {
            return null;
        }
        return self::statusOf($row);
    }

    public static function isEnrolled(int $courseSrl, int $memberSrl): bool
    {
        return self::getStatus($courseSrl, $memberSrl) === self::APPROVED;
    }

    public static function request(int $courseSrl, int $memberSrl, bool $autoApprove): \BaseObject
    {
        if (self::getStatus($courseSrl, $memberSrl) === self::SUSPENDED) {
            return new \BaseObject(-1, '수강이 정지되어 있습니다. 교수 또는 조교에게 문의하세요.');
        }
        return self::setStatus(
            $courseSrl,
            $memberSrl,
            $autoApprove ? self::APPROVED : self::PENDING,
            $autoApprove ? $memberSrl : 0
        );
    }

    public static function approve(int $courseSrl, int $memberSrl, int $decisionBy): \BaseObject
    {
        return self::setStatus($courseSrl, $memberSrl, self::APPROVED, $decisionBy);
    }

    public static function reject(int $courseSrl, int $memberSrl, int $decisionBy): \BaseObject
    {
        return self::setStatus($courseSrl, $memberSrl, self::REJECTED, $decisionBy);
    }

    public static function withdraw(int $courseSrl, int $memberSrl): \BaseObject
    {
        if (self::getStatus($courseSrl, $memberSrl) === self::SUSPENDED) {
            return new \BaseObject(-1, '정지된 수강 상태는 직접 변경할 수 없습니다.');
        }
        return self::setStatus($courseSrl, $memberSrl, self::WITHDRAWN, $memberSrl);
    }

    public static function suspend(int $courseSrl, int $memberSrl, int $decisionBy): \BaseObject
    {
        return self::setStatus($courseSrl, $memberSrl, self::SUSPENDED, $decisionBy);
    }

    /** @return array<int, object> */
    public static function getByCourse(int $courseSrl): array
    {
        $output = executeQueryArray('simple_video_learning.getEnrollmentsByCourseAll', ['course_srl' => $courseSrl]);
        if (!$output->toBool() || empty($output->data)) {
            return [];
        }
        return is_array($output->data) ? $output->data : [$output->data];
    }

    /** @return array<int, object> */
    public static function getApprovedByCourse(int $courseSrl): array
    {
        return array_values(array_filter(
            self::getByCourse($courseSrl),
            static fn(object $row): bool =>
                (string)($row->active ?? 'N') === 'Y' && self::statusOf($row) === self::APPROVED
        ));
    }

    /** @return array<int, object> */
    public static function getPendingByCourse(int $courseSrl): array
    {
        return array_values(array_filter(
            self::getByCourse($courseSrl),
            static fn(object $row): bool =>
                (string)($row->active ?? 'N') === 'Y' &&
                (string)($row->status ?? '') === self::PENDING
        ));
    }

    /** @return array<int, object> */
    public static function getByMember(int $memberSrl): array
    {
        $output = executeQueryArray('simple_video_learning.getEnrollmentsByMember', [
            'member_srl' => $memberSrl,
            'active' => 'Y',
        ]);
        if (!$output->toBool() || empty($output->data)) {
            return [];
        }
        return is_array($output->data) ? $output->data : [$output->data];
    }

    private static function setStatus(int $courseSrl, int $memberSrl, string $status, int $decisionBy): \BaseObject
    {
        $existing = self::get($courseSrl, $memberSrl);
        $now = date('YmdHis');
        $payload = [
            'enrollment_key' => self::key($courseSrl, $memberSrl),
            'course_srl' => $courseSrl,
            'member_srl' => $memberSrl,
            'active' => 'Y',
            'status' => $status,
            'decision_by' => $decisionBy,
            'decision_at' => in_array($status, [self::APPROVED, self::REJECTED, self::WITHDRAWN, self::SUSPENDED], true) ? $now : '',
            'last_update' => $now,
        ];

        if ($existing) {
            return executeQuery('simple_video_learning.updateEnrollment', $payload);
        }

        $payload['enrollment_srl'] = getNextSequence();
        $payload['regdate'] = $now;
        return executeQuery('simple_video_learning.insertEnrollment', $payload);
    }

    private static function key(int $courseSrl, int $memberSrl): string
    {
        return $courseSrl . ':' . $memberSrl;
    }

    private static function statusOf(object $row): ?string
    {
        $status = (string)($row->status ?? '');
        // Older releases stored staff-imposed suspensions as withdrawals.
        if ($status === self::WITHDRAWN && (int)($row->decision_by ?? 0) > 0
            && (int)$row->decision_by !== (int)($row->member_srl ?? 0)
        ) {
            return self::SUSPENDED;
        }
        return in_array($status, [self::PENDING, self::APPROVED, self::REJECTED, self::WITHDRAWN, self::SUSPENDED], true)
            ? $status : null;
    }
}
