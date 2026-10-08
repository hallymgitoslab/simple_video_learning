<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Models;

class CourseApiTokenModel
{
    public static function generate(int $courseSrl, int $createdBy, string $label = ''): array
    {
        $plain = 'slms_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $plain);
        $now = date('YmdHis');
        $output = executeQuery('simple_video_learning.insertCourseApiToken', [
            'token_srl' => getNextSequence(),
            'token_hash' => $hash,
            'token_prefix' => substr($plain, 0, 13),
            'course_srl' => $courseSrl,
            'label' => trim($label),
            'created_by' => $createdBy,
            'active' => 'Y',
            'regdate' => $now,
            'last_update' => $now,
        ]);
        return [$output, $plain];
    }

    public static function authenticate(string $plain): ?object
    {
        if (!str_starts_with($plain, 'slms_')) {
            return null;
        }
        $output = executeQuery('simple_video_learning.getCourseApiTokenByHash', [
            'token_hash' => hash('sha256', $plain),
        ]);
        if (!$output->toBool() || empty($output->data) || (string)($output->data->active ?? 'N') !== 'Y') {
            return null;
        }
        self::touch((int)$output->data->token_srl);
        return $output->data;
    }

    /** @return array<int, object> */
    public static function getByCourse(int $courseSrl): array
    {
        $output = executeQueryArray('simple_video_learning.getCourseApiTokens', ['course_srl' => $courseSrl, 'active' => 'Y']);
        if (!$output->toBool() || empty($output->data)) {
            return [];
        }
        return is_array($output->data) ? $output->data : [$output->data];
    }

    public static function revoke(int $tokenSrl): \BaseObject
    {
        return executeQuery('simple_video_learning.updateCourseApiToken', [
            'token_srl' => $tokenSrl,
            'active' => 'N',
            'last_update' => date('YmdHis'),
        ]);
    }

    public static function touch(int $tokenSrl): void
    {
        executeQuery('simple_video_learning.updateCourseApiToken', [
            'token_srl' => $tokenSrl,
            'last_used_at' => date('YmdHis'),
            'last_update' => date('YmdHis'),
        ]);
    }
}
