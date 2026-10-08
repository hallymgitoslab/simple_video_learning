<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src;

use Context;
use ModuleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\ConfigModel;
use Rhymix\Framework\Session;

class ModuleBase extends \ModuleObject
{
    public function checkUpdate(): bool
    {
        $db = \DB::getInstance();

        foreach (['simple_video_learning_courses', 'simple_video_learning_lectures', 'simple_video_learning_progress', 'simple_video_learning_enrollments', 'simple_video_learning_course_roles', 'simple_video_learning_course_api_tokens'] as $table) {
            if (!$db->isTableExists($table)) {
                return true;
            }
        }

        $requiredCourseColumns = ['num_sections', 'enrollment_enabled', 'enrollment_mode'];
        foreach ($requiredCourseColumns as $column) {
            if (!$db->isColumnExists('simple_video_learning_courses', $column)) {
                return true;
            }
        }

        $requiredEnrollmentColumns = ['status', 'decision_by', 'decision_at'];
        foreach ($requiredEnrollmentColumns as $column) {
            if (!$db->isColumnExists('simple_video_learning_enrollments', $column)) {
                return true;
            }
        }

        $requiredLectureColumns = ['course_srl', 'week_no', 'sort_order', 'uploader_srl'];
        foreach ($requiredLectureColumns as $column) {
            if (!$db->isColumnExists('simple_video_learning_lectures', $column)) {
                return true;
            }
        }

        $requiredLectureIndexes = ['idx_course_srl', 'idx_week_no', 'idx_uploader_srl'];
        foreach ($requiredLectureIndexes as $index) {
            if (!$db->isIndexExists('simple_video_learning_lectures', $index)) {
                return true;
            }
        }

        // Schema creation can succeed before a data migration fails. Keep the
        // update available until all legacy open courses have been converted.
        $pending = executeQuery('simple_video_learning.getPendingOpenEnrollmentMigration', [
            'enrollment_enabled' => 'N',
            'enrollment_mode' => 'auto',
        ]);
        return !$pending->toBool() || (int)($pending->data->count ?? 0) > 0
            || (new ConfigModel())->needsTokenMigration();
    }

    public function moduleUpdate()
    {
        $db = \DB::getInstance();
        $schemaDir = rtrim((string)$this->module_path, '/\\') . '/schemas/';

        foreach (['simple_video_learning_courses', 'simple_video_learning_lectures', 'simple_video_learning_progress', 'simple_video_learning_enrollments', 'simple_video_learning_course_roles', 'simple_video_learning_course_api_tokens'] as $table) {
            if (!$db->isTableExists($table)) {
                $output = $db->createTable($schemaDir . $table . '.xml');
                if (!$output->toBool()) {
                    return $output;
                }
            }
        }

        if (!$db->isColumnExists('simple_video_learning_courses', 'num_sections')) {
            $output = $db->addColumn('simple_video_learning_courses', 'num_sections', 'number', 3, 15, true, 'description');
            if (!$output->toBool()) {
                return $output;
            }
        }

        if (!$db->isColumnExists('simple_video_learning_courses', 'enrollment_enabled')) {
            $output = $db->addColumn('simple_video_learning_courses', 'enrollment_enabled', 'char', 1, 'Y', true, 'num_sections');
            if (!$output->toBool()) {
                return $output;
            }
        }
        if (!$db->isIndexExists('simple_video_learning_courses', 'idx_enrollment_enabled')) {
            $output = $db->addIndex('simple_video_learning_courses', 'idx_enrollment_enabled', ['enrollment_enabled']);
            if (!$output->toBool()) {
                return $output;
            }
        }

        if (!$db->isColumnExists('simple_video_learning_courses', 'enrollment_mode')) {
            $output = $db->addColumn('simple_video_learning_courses', 'enrollment_mode', 'varchar', 20, 'auto', true, 'enrollment_enabled');
            if (!$output->toBool()) {
                return $output;
            }
        }

        // Retry independently of column creation, and only convert rows still
        // carrying the legacy flag with the newly added column's default mode.
        $migrate = executeQuery('simple_video_learning.migrateOpenEnrollmentMode', [
            'enrollment_mode' => 'open',
            'enrollment_enabled' => 'N',
            'previous_enrollment_mode' => 'auto',
        ]);
        if (!$migrate->toBool()) {
            return $migrate;
        }

        if (!$db->isIndexExists('simple_video_learning_courses', 'idx_enrollment_mode')) {
            $output = $db->addIndex('simple_video_learning_courses', 'idx_enrollment_mode', ['enrollment_mode']);
            if (!$output->toBool()) {
                return $output;
            }
        }

        if (!$db->isColumnExists('simple_video_learning_enrollments', 'status')) {
            $output = $db->addColumn('simple_video_learning_enrollments', 'status', 'varchar', 20, 'approved', true, 'active');
            if (!$output->toBool()) {
                return $output;
            }
        }
        if (!$db->isColumnExists('simple_video_learning_enrollments', 'decision_by')) {
            $output = $db->addColumn('simple_video_learning_enrollments', 'decision_by', 'number', 11, 0, true, 'status');
            if (!$output->toBool()) {
                return $output;
            }
        }
        if (!$db->isColumnExists('simple_video_learning_enrollments', 'decision_at')) {
            $output = $db->addColumn('simple_video_learning_enrollments', 'decision_at', 'date', null, null, false, 'decision_by');
            if (!$output->toBool()) {
                return $output;
            }
        }
        if (!$db->isIndexExists('simple_video_learning_enrollments', 'idx_status')) {
            $output = $db->addIndex('simple_video_learning_enrollments', 'idx_status', ['status']);
            if (!$output->toBool()) {
                return $output;
            }
        }

        $lectureColumns = [
            ['course_srl', 'number', 11, 0, true, 'module_srl'],
            ['week_no', 'number', 3, 1, true, 'course_srl'],
            ['sort_order', 'number', 6, 0, true, 'week_no'],
            ['uploader_srl', 'number', 11, 0, true, 'sort_order'],
        ];
        foreach ($lectureColumns as [$name, $type, $size, $default, $notnull, $after]) {
            if (!$db->isColumnExists('simple_video_learning_lectures', $name)) {
                $output = $db->addColumn('simple_video_learning_lectures', $name, $type, $size, $default, $notnull, $after);
                if (!$output->toBool()) {
                    return $output;
                }
            }
        }

        $lectureIndexes = [
            'idx_course_srl' => ['course_srl'],
            'idx_week_no' => ['week_no'],
            'idx_uploader_srl' => ['uploader_srl'],
        ];
        foreach ($lectureIndexes as $index => $columns) {
            if (!$db->isIndexExists('simple_video_learning_lectures', $index)) {
                $output = $db->addIndex('simple_video_learning_lectures', $index, $columns);
                if (!$output->toBool()) {
                    return $output;
                }
            }
        }

        return (new ConfigModel())->migrateLegacyToken();
    }

    protected function ensureDatabaseReady(bool $allowMigrate = false): void
    {
        if (!$this->checkUpdate()) {
            return;
        }

        if (!$allowMigrate) {
            throw new \RuntimeException('Simple Video Learning 데이터베이스 업데이트가 필요합니다. 관리자에서 모듈 업데이트를 실행하세요.');
        }

        $output = $this->moduleUpdate();
        if (is_object($output) && method_exists($output, 'toBool') && !$output->toBool()) {
            throw new \RuntimeException(method_exists($output, 'getMessage') ? (string)$output->getMessage() : 'Database update failed.');
        }

        if ($this->checkUpdate()) {
            throw new \RuntimeException('Simple Video Learning 데이터베이스 업데이트를 완료하지 못했습니다.');
        }
    }

    protected function parseSectionNumber($value): int
    {
        if ((!is_int($value) && (!is_string($value) || !preg_match('/^[0-9]+$/D', $value)))
            || (int)$value < 1 || (int)$value > 99
        ) {
            throw new \InvalidArgumentException('sectionnum must be an integer between 1 and 99');
        }
        return (int)$value;
    }

    protected function resolveLearningModuleInfo(?string $preferredMid = null): object
    {
        $preferredMid = trim((string)$preferredMid);
        if ($preferredMid !== '') {
            $candidate = ModuleModel::getModuleInfoByMid($preferredMid);
            if ($candidate && (string)($candidate->module ?? '') === 'simple_video_learning') {
                return $candidate;
            }
        }

        $instances = ModuleModel::getMidList((object)['module' => 'simple_video_learning']);
        if ($instances) {
            foreach ($instances as $instance) {
                if ($instance && !empty($instance->module_srl) && !empty($instance->mid)) {
                    return $instance;
                }
            }
        }

        throw new \RuntimeException('Simple Video Learning 이러닝 메뉴가 없습니다. 사이트 메뉴 편집에서 Simple Video Learning 강의 메뉴를 먼저 생성하세요.');
    }

    protected function useFrontendSkin(string $template): void
    {
        $skin = trim((string)($this->module_info->skin ?? ''));
        if ($skin === '' || $skin === '/USE_DEFAULT/' || $skin === '/USE_RESPONSIVE/') {
            $skin = (string)(ModuleModel::getModuleDefaultSkin('simple_video_learning', 'P') ?: 'default');
        }

        $templatePath = $this->module_path . 'skins/' . $skin . '/';
        if (!is_dir($templatePath)) {
            $skin = 'default';
            $templatePath = $this->module_path . 'skins/default/';
        }

        Context::set('simple_video_learning_mid', (string)($this->mid ?: Context::get('mid')));
        Context::set('simple_video_learning_skin', $skin);
        $loggedInfo = Context::get('logged_info');
        Context::set(
            'simple_video_learning_csrf_token',
            ($loggedInfo && !empty($loggedInfo->member_srl)) ? Session::getGenericToken() : ''
        );
        Context::set(
            'simple_video_learning_can_upload',
            !empty($this->grant->upload) || !empty($this->grant->manager) || !empty($this->grant->root)
        );

        Context::loadFile(['./modules/simple_video_learning/skins/' . $skin . '/style.css', 'head']);
        $this->setTemplatePath($templatePath);
        $this->setTemplateFile($template);
    }
}
