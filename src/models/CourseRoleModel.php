<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Models;

class CourseRoleModel
{
    public const TEACHER = 'teacher';
    public const ASSISTANT = 'assistant';

    public static function get(int $courseSrl, int $memberSrl): ?object
    {
        if (!\DB::getInstance()->isTableExists('simple_video_learning_course_roles')) {
            return null;
        }
        $output = executeQuery('simple_video_learning.getCourseRole', ['role_key' => self::key($courseSrl, $memberSrl)]);
        return ($output->toBool() && !empty($output->data)) ? $output->data : null;
    }

    public static function getRole(int $courseSrl, int $memberSrl): ?string
    {
        $row = self::get($courseSrl, $memberSrl);
        if (!$row || (string)($row->active ?? 'N') !== 'Y') {
            return null;
        }
        $role = (string)($row->role ?? '');
        return in_array($role, [self::TEACHER, self::ASSISTANT], true) ? $role : null;
    }

    public static function isStaff(int $courseSrl, int $memberSrl): bool
    {
        return self::getRole($courseSrl, $memberSrl) !== null;
    }

    public static function canEditActivities(int $courseSrl, int $memberSrl): bool
    {
        return self::getRole($courseSrl, $memberSrl) === self::TEACHER;
    }

    public static function canManageEnrollments(int $courseSrl, int $memberSrl): bool
    {
        return in_array(self::getRole($courseSrl, $memberSrl), [self::TEACHER, self::ASSISTANT], true);
    }

    public static function canViewProgress(int $courseSrl, int $memberSrl): bool
    {
        return self::canManageEnrollments($courseSrl, $memberSrl);
    }

    public static function assign(int $courseSrl, int $memberSrl, string $role, int $assignedBy = 0): \BaseObject
    {
        if (!in_array($role, [self::TEACHER, self::ASSISTANT], true)) {
            return new \BaseObject(-1, '지원하지 않는 수업 역할입니다.');
        }

        $existing = self::get($courseSrl, $memberSrl);
        $now = date('YmdHis');
        $payload = [
            'role_key' => self::key($courseSrl, $memberSrl),
            'course_srl' => $courseSrl,
            'member_srl' => $memberSrl,
            'role' => $role,
            'active' => 'Y',
            'assigned_by' => $assignedBy,
            'last_update' => $now,
        ];

        if ($existing) {
            return executeQuery('simple_video_learning.updateCourseRole', $payload);
        }

        $payload['role_srl'] = getNextSequence();
        $payload['regdate'] = $now;
        return executeQuery('simple_video_learning.insertCourseRole', $payload);
    }

    public static function remove(int $courseSrl, int $memberSrl, int $assignedBy = 0): \BaseObject
    {
        return executeQuery('simple_video_learning.updateCourseRole', [
            'role_key' => self::key($courseSrl, $memberSrl),
            'active' => 'N',
            'assigned_by' => $assignedBy,
            'last_update' => date('YmdHis'),
        ]);
    }

    /** @return array<int, object> */
    public static function getByCourse(int $courseSrl): array
    {
        $output = executeQueryArray('simple_video_learning.getCourseRoles', ['course_srl' => $courseSrl, 'active' => 'Y']);
        if (!$output->toBool() || empty($output->data)) {
            return [];
        }
        return is_array($output->data) ? $output->data : [$output->data];
    }

    private static function key(int $courseSrl, int $memberSrl): string
    {
        return $courseSrl . ':' . $memberSrl;
    }
}
