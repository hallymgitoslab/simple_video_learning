<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Models;

class CourseModel
{
    public static function get(int $courseSrl): ?object
    {
        $output = executeQuery('simple_video_learning.getCourse', ['course_srl' => $courseSrl]);
        return ($output->toBool() && !empty($output->data)) ? $output->data : null;
    }

    public static function getByCode(string $courseCode): ?object
    {
        $output = executeQuery('simple_video_learning.getCourseByCode', ['course_code' => $courseCode]);
        return ($output->toBool() && !empty($output->data)) ? $output->data : null;
    }

    /** @return array<int, object> */
    public static function getAll(): array
    {
        $output = executeQueryArray('simple_video_learning.getCourses');
        if (!$output->toBool() || empty($output->data)) {
            return [];
        }
        return is_array($output->data) ? $output->data : [$output->data];
    }

    /** @return array<int, object> */
    public static function getAllSafely(): array
    {
        try {
            $db = \DB::getInstance();
            if (!$db->isTableExists('simple_video_learning_courses')) {
                return [];
            }

            $output = executeQueryArray('simple_video_learning.getCourses');
            if (!$output->toBool()) {
                error_log('[simple_video_learning] getCourses failed: ' . (string)$output->getMessage());
                return [];
            }
            if (empty($output->data)) {
                return [];
            }
            return is_array($output->data) ? $output->data : [$output->data];
        } catch (\Throwable $e) {
            error_log('[simple_video_learning] getAllSafely exception: ' . $e->getMessage());
            return [];
        }
    }

    public static function save(array $data): \BaseObject
    {
        $now = date('YmdHis');
        $courseSrl = (int)($data['course_srl'] ?? 0);
        $existing = $courseSrl > 0 ? self::get($courseSrl) : null;

        $data['num_sections'] = max(1, min(99, (int)($data['num_sections'] ?? ($existing->num_sections ?? 15))));

        $legacyEnabled = (string)($data['enrollment_enabled'] ?? ($existing->enrollment_enabled ?? 'Y')) === 'N' ? 'N' : 'Y';
        $mode = (string)($data['enrollment_mode'] ?? ($existing->enrollment_mode ?? ($legacyEnabled === 'N' ? 'open' : 'auto')));
        if (!in_array($mode, ['open', 'auto', 'approval', 'closed'], true)) {
            $mode = $legacyEnabled === 'N' ? 'open' : 'auto';
        }
        $data['enrollment_mode'] = $mode;
        $data['enrollment_enabled'] = $mode === 'open' ? 'N' : 'Y';

        if ($existing) {
            $data['last_update'] = $now;
            return executeQuery('simple_video_learning.updateCourse', $data);
        }

        $data['course_srl'] = getNextSequence();
        $data['regdate'] = $now;
        $data['last_update'] = $now;
        return executeQuery('simple_video_learning.insertCourse', $data);
    }

    public static function delete(int $courseSrl): \BaseObject
    {
        $db = \DB::getInstance();
        $db->begin();
        try {
            foreach (['deleteCourseApiTokensByCourse', 'deleteCourseRolesByCourse', 'deleteEnrollmentsByCourse', 'deleteCourse'] as $query) {
                $output = executeQuery('simple_video_learning.' . $query, ['course_srl' => $courseSrl]);
                if (!$output->toBool()) {
                    $db->rollback();
                    return $output;
                }
            }
            $db->commit();
            return $output;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
