<?php

declare(strict_types=1);

// Standalone regression tests for the real controllers/models, with in-memory
// Rhymix/database adapters. Run: php tests/security_regressions.php
namespace Rhymix\Framework\Exceptions {
    class NotPermitted extends \RuntimeException {}
    class MustLogin extends \RuntimeException {}
    class TargetNotFound extends \RuntimeException {}
}

namespace Rhymix\Modules\Simple_video_learning\Src\Models {
    function time(): int { return $GLOBALS['fixture']['now']; }
    function date(string $format): string { return \date($format, time()); }
}

namespace Rhymix\Framework {
    class Session
    {
        public static function set(string $key, $value): void { $GLOBALS['fixture']['session'][$key] = $value; }
        public static function get(string $key) { return $GLOBALS['fixture']['session'][$key] ?? null; }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    date_default_timezone_set('UTC');
    error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    class BaseObject
    {
        public $data;
        private $error;
        private $message;
        public function __construct(int $error = 0, string $message = '', $data = null)
        {
            $this->error = $error;
            $this->message = $message;
            $this->data = $data;
        }
        public function toBool(): bool { return $this->error === 0; }
        public function getMessage(): string { return $this->message; }
    }

    class ModuleObject
    {
        public $module_path;
        public $grant;
        public $mid = 'learning';
        public $response = [];
        public function __construct()
        {
            $this->module_path = $GLOBALS['sourceRoot'] . '/';
            $this->grant = (object)[];
        }
        public function add(string $key, $value): void { $this->response[$key] = $value; }
        public function setMessage(string $message): void {}
        public function setRedirectUrl(string $url): void {}
    }

    class Context
    {
        public static $vars = [];
        public static function get(string $key) { return self::$vars[$key] ?? null; }
        public static function setResponseMethod(string $method): void {}
    }

    class ModuleModel
    {
        public static function getModuleConfig(string $key): object { return $GLOBALS['fixture']['config']; }
        public static function getModuleInfoByModuleSrl(int $srl): object
        {
            return (object)['module' => 'simple_video_learning', 'module_srl' => $srl];
        }
        public static function getGrant(object $module, object $member): object
        {
            return (object)['access' => true, 'view' => true];
        }
    }

    class MemberModel
    {
        public static function getMemberInfoByUserID(string $id): ?object
        {
            return $GLOBALS['fixture']['members'][$id] ?? null;
        }
    }

    class ModuleController
    {
        public static function getInstance(): self { return new self(); }
        public function insertModuleConfig(string $key, object $config): BaseObject
        {
            $GLOBALS['fixture']['config'] = clone $config;
            return new BaseObject();
        }
    }

    class DB
    {
        private $snapshot;
        public function begin(): void { $this->snapshot = unserialize(serialize($GLOBALS['fixture'])); }
        public function rollback(): void { $GLOBALS['fixture'] = $this->snapshot; }
        public function commit(): void { $this->snapshot = null; }
        public static function getInstance(): self { return new self(); }
        public function isTableExists(string $table): bool { return true; }
        public function isColumnExists(string $table, string $column): bool
        {
            return $column !== 'enrollment_mode' || $GLOBALS['fixture']['mode_column'];
        }
        public function isIndexExists(string $table, string $index): bool { return true; }
        public function addColumn(...$args): BaseObject
        {
            if ($args[1] === 'enrollment_mode') {
                $GLOBALS['fixture']['mode_column'] = true;
                foreach ($GLOBALS['fixture']['courses'] as $course) {
                    $course->enrollment_mode = 'auto';
                }
            }
            return new BaseObject();
        }
    }

    function getNextSequence(): int { return ++$GLOBALS['fixture']['sequence']; }

    // Evaluate the actual XML predicates for migration reads/writes so the
    // tests also exercise the distinction between pending and explicit modes.
    function migrationRows(string $name, array $args): array
    {
        $query = simplexml_load_file($GLOBALS['sourceRoot'] . '/queries/' . $name . '.xml');
        $rows = [];
        foreach ($GLOBALS['fixture']['courses'] as $course) {
            $matches = true;
            foreach ($query->conditions->condition as $condition) {
                $column = (string)$condition['column'];
                $var = (string)$condition['var'];
                $matches = $matches && ($course->{$column} ?? null) === ($args[$var] ?? null);
            }
            if ($matches) { $rows[] = $course; }
        }
        return $rows;
    }

    function executeQuery(string $query, array $args = []): BaseObject
    {
        $f = &$GLOBALS['fixture'];
        $name = substr($query, strlen('simple_video_learning.'));
        switch ($name) {
            case 'getProgress':
                $row = $f['progress'][$args['progress_key']] ?? null;
                return new BaseObject(0, '', $row ? clone $row : null);
            case 'insertProgress':
            case 'updateProgress':
                $f['progress'][$args['progress_key']] = (object)$args;
                return new BaseObject();
            case 'getLectureByDocument':
                return new BaseObject(0, '', (int)$args['document_srl'] === (int)$f['lecture']->document_srl ? clone $f['lecture'] : null);
            case 'getLectureByExternalId':
                return new BaseObject(0, '', clone $f['lecture']);
            case 'updateLecture':
                foreach ($args as $key => $value) { $f['lecture']->{$key} = $value; }
                return new BaseObject();
            case 'getCourse':
                return new BaseObject(0, '', $f['courses'][$args['course_srl']] ?? null);
            case 'getEnrollment':
                $row = $f['enrollments'][$args['enrollment_key']] ?? null;
                return new BaseObject(0, '', $row ? clone $row : null);
            case 'insertEnrollment':
            case 'updateEnrollment':
                $f['enrollments'][$args['enrollment_key']] = (object)$args;
                return new BaseObject();
            case 'deleteCourseApiTokensByCourse':
            case 'deleteCourseRolesByCourse':
            case 'deleteEnrollmentsByCourse':
            case 'deleteCourse':
                if ($f['fail_delete'] === $name) { return new BaseObject(-1, 'Injected delete failure'); }
                if ($name === 'deleteCourse') { unset($f['courses'][$args['course_srl']]); }
                else {
                    $table = ['deleteCourseApiTokensByCourse' => 'tokens', 'deleteCourseRolesByCourse' => 'roles', 'deleteEnrollmentsByCourse' => 'enrollments'][$name];
                    foreach ($f[$table] as $key => $row) {
                        if ((int)($row->course_srl ?? explode(':', (string)$key)[0]) === (int)$args['course_srl']) {
                            unset($f[$table][$key]);
                        }
                    }
                }
                return new BaseObject();
            case 'getCourseRole':
                return new BaseObject(0, '', $f['roles'][$args['role_key']] ?? null);
            case 'insertCourseRole':
            case 'updateCourseRole':
                $key = $args['role_key'];
                $row = $f['roles'][$key] ?? (object)[];
                foreach ($args as $field => $value) { $row->{$field} = $value; }
                $f['roles'][$key] = $row;
                return new BaseObject();
            case 'getPendingOpenEnrollmentMigration':
                return new BaseObject(0, '', (object)['count' => count(migrationRows($name, $args))]);
            case 'migrateOpenEnrollmentMode':
                if ($f['fail_migration']) {
                    $f['fail_migration'] = false;
                    return new BaseObject(-1, 'Injected migration failure');
                }
                foreach (migrationRows($name, $args) as $course) {
                    $course->enrollment_mode = $args['enrollment_mode'];
                }
                return new BaseObject();
            default:
                throw new \RuntimeException('Unexpected query: ' . $query);
        }
    }

    function executeQueryArray(string $query, array $args = []): BaseObject
    {
        if ($query === 'simple_video_learning.getEnrollmentsByCourseAll') {
            return new BaseObject(0, '', array_values($GLOBALS['fixture']['enrollments']));
        }
        throw new \RuntimeException('Unexpected array query: ' . $query);
    }

    function resetFixture(): void
    {
        $GLOBALS['fixture'] = [
            'now' => 1700000000, 'sequence' => 1000, 'progress' => [],
            'mode_column' => true, 'fail_migration' => false, 'fail_delete' => '',
            'config' => (object)[], 'session' => [], 'tokens' => [(object)['course_srl' => 1]],
            'enrollments' => ['1:404' => (object)[
                'member_srl' => 404, 'course_srl' => 1, 'active' => 'Y', 'status' => 'approved', 'decision_by' => 404,
            ]],
            'lecture' => (object)[
                'external_id' => 'lecture-1', 'document_srl' => 10, 'module_srl' => 1,
                'course_srl' => 1, 'duration' => 3600, 'completion_threshold' => 80,
            ],
            'courses' => [1 => (object)[
                'course_srl' => 1, 'is_published' => 'Y',
                'enrollment_enabled' => 'Y', 'enrollment_mode' => 'approval',
            ]],
            'members' => [
                'teacher-b' => (object)['member_srl' => 202],
                'assistant' => (object)['member_srl' => 303],
            ],
            'roles' => [
                '1:101' => (object)['role' => 'teacher', 'active' => 'Y'],
                '1:202' => (object)['role' => 'teacher', 'active' => 'Y'],
                '1:303' => (object)['role' => 'assistant', 'active' => 'Y'],
            ],
        ];
        Context::$vars = [
            'logged_info' => (object)['member_srl' => 404, 'is_admin' => 'N'],
            'document_srl' => 10, 'course_srl' => 1, 'access_module_srl' => 1,
            'current_time' => 3600, 'playing' => 'Y', 'duration' => 1,
            'success_return_url' => '/learning',
        ];
    }

    function expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
    }

    $sourceRoot = getenv('SVL_SOURCE_ROOT') ?: dirname(__DIR__);
    foreach ([
        'src/ModuleBase.php', 'src/models/ProgressModel.php', 'src/models/LectureModel.php',
        'src/models/CourseModel.php', 'src/models/CourseRoleModel.php', 'src/models/EnrollmentModel.php',
        'src/models/ConfigModel.php', 'src/controllers/ProgressController.php', 'src/controllers/CourseOperationsController.php',
        'src/controllers/CourseController.php', 'src/controllers/EnrollmentController.php', 'src/EventHandler.php',
    ] as $file) { require_once $sourceRoot . '/' . $file; }

    use Rhymix\Modules\Simple_video_learning\Src\Models\ProgressModel;
    use Rhymix\Modules\Simple_video_learning\Src\Controllers\ProgressController;
    use Rhymix\Modules\Simple_video_learning\Src\Controllers\CourseOperationsController;
    use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;
    use Rhymix\Modules\Simple_video_learning\Src\Models\EnrollmentModel;
    use Rhymix\Modules\Simple_video_learning\Src\Models\ConfigModel;
    use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
    use Rhymix\Modules\Simple_video_learning\Src\Controllers\CourseController;
    use Rhymix\Modules\Simple_video_learning\Src\EventHandler;

    $tests = [];
    $tests['rapid heartbeats cannot mint watch time'] = static function (): void {
        for ($i = 0; $i < 721; $i++) {
            $result = ProgressModel::record(10, 404, 3600, 3600, true, 80);
        }
        expect($result['watched_seconds'] === 0 && !$result['completed'], 'Rapid requests granted credit');
    };
    $tests['normal heartbeats use elapsed time and cap long gaps'] = static function (): void {
        ProgressModel::record(10, 404, 0, 3600, true, 80);
        $GLOBALS['fixture']['now'] += 5;
        $result = ProgressModel::record(10, 404, 5, 3600, true, 80);
        expect($result['watched_seconds'] === 5, 'Normal playback lost credit');
        $GLOBALS['fixture']['now'] += 60;
        $result = ProgressModel::record(10, 404, 3600, 3600, true, 80);
        expect($result['watched_seconds'] === 15, 'Long gap bypassed the heartbeat cap');
    };
    $tests['paused seeks cannot move beyond the watched frontier'] = static function (): void {
        ProgressModel::record(10, 404, 0, 3600, true, 80);
        $GLOBALS['fixture']['now'] += 5;
        ProgressModel::record(10, 404, 5, 3600, true, 80);
        $paused = ProgressModel::record(10, 404, 3600, 3600, false, 80);
        expect($paused['position'] === 5, 'Pause request advanced the frontier');
        $result = ProgressModel::record(10, 404, 3600, 3600, true, 80);
        expect($result['watched_seconds'] === 5, 'Pause/play requests minted credit');
    };
    $tests['learner duration cannot repair unknown lecture metadata'] = static function (): void {
        $GLOBALS['fixture']['lecture']->duration = 0;
        $controller = new ProgressController();
        $controller->procSimple_video_learningProgress();
        $GLOBALS['fixture']['now'] += 5;
        $controller->procSimple_video_learningProgress();
        expect($GLOBALS['fixture']['lecture']->duration === 0, 'Learner changed shared duration');
        expect(!$controller->response['completed'], 'Unknown duration earned completion');
        expect($controller->response['duration_known'] === false, 'Player was not informed of unknown duration');
    };
    $tests['known durations ignore learner supplied values'] = static function (): void {
        $controller = new ProgressController();
        $controller->procSimple_video_learningProgress();
        $GLOBALS['fixture']['now'] += 5;
        $controller->procSimple_video_learningProgress();
        expect($GLOBALS['fixture']['lecture']->duration === 3600, 'Known duration changed');
        expect($controller->response['watched_seconds'] === 5, 'Known duration stopped recording');
        expect($controller->response['duration_known'] === true, 'Known duration was reported as unknown');
    };
    $tests['teacher cannot demote another teacher through assistant assignment'] = static function (): void {
        Context::$vars['logged_info']->member_srl = 101;
        Context::$vars['role'] = 'assistant';
        Context::$vars['user_id'] = 'teacher-b';
        $denied = false;
        try { (new CourseOperationsController())->procSimple_video_learningAssignCourseRole(); }
        catch (\Rhymix\Framework\Exceptions\NotPermitted $e) { $denied = true; }
        expect($denied, 'Teacher demotion was permitted');
        expect($GLOBALS['fixture']['roles']['1:202']->role === 'teacher', 'Target teacher role changed');
    };
    $tests['teacher may assign assistants and administrator may change teachers'] = static function (): void {
        Context::$vars['logged_info']->member_srl = 101;
        Context::$vars['role'] = 'assistant';
        Context::$vars['user_id'] = 'assistant';
        (new CourseOperationsController())->procSimple_video_learningAssignCourseRole();
        expect($GLOBALS['fixture']['roles']['1:303']->role === 'assistant', 'Assistant assignment failed');
        Context::$vars['logged_info']->is_admin = 'Y';
        Context::$vars['user_id'] = 'teacher-b';
        (new CourseOperationsController())->procSimple_video_learningAssignCourseRole();
        expect($GLOBALS['fixture']['roles']['1:202']->role === 'assistant', 'Administrator could not change role');
    };
    $tests['failed data migration remains pending after column creation and can retry'] = static function (): void {
        $GLOBALS['fixture']['mode_column'] = false;
        $GLOBALS['fixture']['fail_migration'] = true;
        $GLOBALS['fixture']['courses'][1]->enrollment_enabled = 'N';
        $module = new ModuleBase();
        expect(!$module->moduleUpdate()->toBool(), 'Injected failure was ignored');
        expect($GLOBALS['fixture']['mode_column'], 'Column was not created before failure');
        expect($module->checkUpdate(), 'Failed migration disappeared from update checks');
        expect($module->moduleUpdate()->toBool(), 'Retry failed');
        expect($GLOBALS['fixture']['courses'][1]->enrollment_mode === 'open', 'Retry skipped legacy data');
        expect(!$module->checkUpdate(), 'Successful migration still appears pending');
    };
    $tests['migration with existing column preserves explicitly configured modes'] = static function (): void {
        $GLOBALS['fixture']['courses'] = [
            1 => (object)['enrollment_enabled' => 'N', 'enrollment_mode' => 'auto'],
            2 => (object)['enrollment_enabled' => 'Y', 'enrollment_mode' => 'approval'],
            3 => (object)['enrollment_enabled' => 'Y', 'enrollment_mode' => 'closed'],
            4 => (object)['enrollment_enabled' => 'N', 'enrollment_mode' => 'open'],
            5 => (object)['enrollment_enabled' => 'N', 'enrollment_mode' => 'closed'],
        ];
        $module = new ModuleBase();
        expect($module->checkUpdate(), 'Pending rows with existing schema were missed');
        expect($module->moduleUpdate()->toBool(), 'Existing-column migration failed');
        expect($GLOBALS['fixture']['courses'][1]->enrollment_mode === 'open', 'Pending row was not converted');
        foreach ([2 => 'approval', 3 => 'closed', 4 => 'open', 5 => 'closed'] as $id => $mode) {
            expect($GLOBALS['fixture']['courses'][$id]->enrollment_mode === $mode, 'Explicit mode was overwritten');
        }
        expect($module->moduleUpdate()->toBool() && !$module->checkUpdate(), 'Migration was not idempotent');
    };

    $tests['suspended students cannot enroll or clear suspension through withdrawal'] = static function (): void {
        expect(EnrollmentModel::suspend(1, 404, 101)->toBool(), 'Suspension failed');
        expect(EnrollmentModel::getStatus(1, 404) === EnrollmentModel::SUSPENDED, 'Suspension collapsed into withdrawal');
        expect(!EnrollmentModel::request(1, 404, true)->toBool(), 'Suspended student self-enrolled');
        expect(!EnrollmentModel::withdraw(1, 404)->toBool(), 'Suspended student cleared suspension');
        expect(EnrollmentModel::approve(1, 404, 101)->toBool() && EnrollmentModel::isEnrolled(1, 404), 'Staff could not restore enrollment');
    };
    $tests['legacy suspensions remain blocked and self withdrawals may re-enroll'] = static function (): void {
        $row = $GLOBALS['fixture']['enrollments']['1:404'];
        $row->status = 'withdrawn';
        $row->decision_by = 101;
        expect(EnrollmentModel::getStatus(1, 404) === EnrollmentModel::SUSPENDED, 'Legacy staff suspension was lost');
        expect(!EnrollmentModel::request(1, 404, true)->toBool(), 'Legacy suspension bypassed');
        $row->decision_by = 404;
        expect(EnrollmentModel::request(1, 404, true)->toBool(), 'Self withdrawal could not re-enroll');
    };
    $tests['missing and malformed enrollment states never count as approval'] = static function (): void {
        foreach ([null, '', 'unexpected', 'APPROVED'] as $status) {
            $row = $GLOBALS['fixture']['enrollments']['1:404'];
            $row->status = $status;
            expect(!EnrollmentModel::isEnrolled(1, 404), 'Malformed status granted enrollment');
            expect(EnrollmentModel::getApprovedByCourse(1) === [], 'Malformed status was listed as approved');
        }
    };
    $tests['suspension blocks open course viewing and progress'] = static function (): void {
        $course = $GLOBALS['fixture']['courses'][1];
        $course->enrollment_mode = 'open';
        EnrollmentModel::suspend(1, 404, 101);
        $method = new \ReflectionMethod(CourseController::class, 'canLearn');
        $method->setAccessible(true);
        expect(!$method->invoke(new CourseController(), $course, Context::get('logged_info')), 'Open course bypassed suspension');
        $denied = false;
        try { (new ProgressController())->procSimple_video_learningProgress(); }
        catch (\Rhymix\Framework\Exceptions\NotPermitted $e) { $denied = true; }
        expect($denied && $GLOBALS['fixture']['progress'] === [], 'Suspended student recorded progress');
    };
    $tests['download handler enforces enrollment and suspension'] = static function (): void {
        $file = (object)['upload_target_type' => 'doc', 'upload_target_srl' => 10];
        EventHandler::beforeDownloadFile($file);
        unset($GLOBALS['fixture']['enrollments']['1:404']);
        $denied = false;
        try { EventHandler::beforeDownloadFile($file); }
        catch (\Rhymix\Framework\Exceptions\NotPermitted $e) { $denied = true; }
        expect($denied, 'Non-enrolled member downloaded a course file');
        $GLOBALS['fixture']['courses'][1]->enrollment_mode = 'open';
        EventHandler::beforeDownloadFile($file);
        EnrollmentModel::suspend(1, 404, 101);
        $denied = false;
        try { EventHandler::beforeDownloadFile($file); }
        catch (\Rhymix\Framework\Exceptions\NotPermitted $e) { $denied = true; }
        expect($denied, 'Suspended member downloaded an open-course file');
        Context::$vars['logged_info'] = null;
        $denied = false;
        try { EventHandler::beforeDownloadFile($file); }
        catch (\Rhymix\Framework\Exceptions\NotPermitted $e) { $denied = true; }
        expect($denied, 'Guest downloaded a course file');
        EventHandler::beforeDownloadFile((object)['upload_target_srl' => 999]);
    };
    $tests['section numbers accept only integers from 1 through 99'] = static function (): void {
        $method = new \ReflectionMethod(ModuleBase::class, 'parseSectionNumber');
        $method->setAccessible(true);
        foreach ([1, 99, '3', '99'] as $value) {
            expect($method->invoke(new ModuleBase(), $value) === (int)$value, 'Valid section rejected');
        }
        foreach ([0, 100, -1, '100', 'bad', '1.5', 1.5, true, [], null] as $value) {
            $denied = false;
            try { $method->invoke(new ModuleBase(), $value); }
            catch (\InvalidArgumentException $e) { $denied = true; }
            expect($denied, 'Invalid section accepted');
        }
    };
    $tests['course deletion rolls back every cleanup failure and commits on success'] = static function (): void {
        foreach (['deleteCourseApiTokensByCourse', 'deleteCourseRolesByCourse', 'deleteEnrollmentsByCourse', 'deleteCourse'] as $failure) {
            resetFixture();
            $GLOBALS['fixture']['fail_delete'] = $failure;
            $before = serialize($GLOBALS['fixture']);
            expect(!CourseModel::delete(1)->toBool(), 'Delete failure was ignored');
            expect(serialize($GLOBALS['fixture']) === $before, 'Partial deletion escaped rollback');
        }
        $GLOBALS['fixture']['fail_delete'] = '';
        expect(CourseModel::delete(1)->toBool(), 'Successful deletion failed');
        expect(!isset($GLOBALS['fixture']['courses'][1]) && $GLOBALS['fixture']['enrollments'] === []
            && $GLOBALS['fixture']['roles'] === [] && $GLOBALS['fixture']['tokens'] === [], 'Deletion did not commit related cleanup');
    };
    $tests['legacy global token migrates to a hash without changing the token'] = static function (): void {
        $plain = 'legacy-secret-for-test';
        $GLOBALS['fixture']['config']->api_token = $plain;
        $module = new ModuleBase();
        expect($module->checkUpdate(), 'Legacy token migration was not detected');
        expect(isset($GLOBALS['fixture']['config']->api_token), 'Checking migration mutated the Rhymix config cache');
        expect($module->moduleUpdate()->toBool(), 'Legacy token migration failed');
        $stored = $GLOBALS['fixture']['config'];
        expect(!isset($stored->api_token), 'Raw global token was retained');
        expect(hash_equals($stored->api_token_hash, hash('sha256', $plain)), 'Existing token changed during migration');
        expect(!$module->checkUpdate(), 'Migrated token remained pending');
    };
    $tests['global token rotation exposes plaintext once and settings preserve the hash'] = static function (): void {
        $model = new ConfigModel();
        expect($model->rotateApiToken()->toBool(), 'Rotation failed');
        $plain = \Rhymix\Framework\Session::get('simple_video_learning.new_global_api_token');
        expect(is_string($plain) && strlen($plain) > 64, 'New token was not made available once');
        expect(!isset($GLOBALS['fixture']['config']->api_token), 'Rotation stored plaintext');
        $hash = $GLOBALS['fixture']['config']->api_token_hash;
        expect(hash_equals($hash, hash('sha256', $plain)), 'Stored hash does not match new token');
        $model->set((object)['author_name' => 'Updated']);
        $model->save();
        expect($GLOBALS['fixture']['config']->api_token_hash === $hash, 'Settings save changed the token');
        \Rhymix\Framework\Session::set('simple_video_learning.new_global_api_token', null);
        expect(\Rhymix\Framework\Session::get('simple_video_learning.new_global_api_token') === null, 'One-time token was not cleared');
    };

    $failures = 0;
    foreach ($tests as $name => $test) {
        resetFixture();
        try { $test(); echo 'PASS ' . $name . PHP_EOL; }
        catch (\Throwable $e) { $failures++; echo 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL; }
    }
    echo count($tests) . ' tests, ' . $failures . ' failures' . PHP_EOL;
    exit($failures > 0 ? 1 : 0);
}
