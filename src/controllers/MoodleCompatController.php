<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use DocumentController;
use DocumentModel;
use FileController;
use FileModel;
use ModuleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\ConfigModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseApiTokenModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\LectureModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

/**
 * Compatibility layer for the subset of Moodle Web Services used by
 * Moodle-compatible publishing clients. This enables existing publishers
 * target Rhymix without modifying the Python application.
 */
class MoodleCompatController extends ModuleBase
{
    private const COMPONENT = 'simplevideotracker';
    private const DRAFT_TTL = 86400;

    public function procSimple_video_learningCompatRest(): void
    {
        $config = (new ConfigModel())->get();
        $token = trim((string)($_POST['wstoken'] ?? ''));
        $tokenScope = $this->authenticateToken((string)$config->api_token_hash, $token);
        if ($tokenScope === false) {
            $this->moodleError('invalidtoken', 'Invalid token');
        }

        $function = trim((string)($_POST['wsfunction'] ?? ''));
        try {
            switch ($function) {
                case 'core_webservice_get_site_info':
                    $this->json($this->siteInfo());
                    break;

                case 'core_enrol_get_users_courses':
                    $this->json($this->courses($config, $tokenScope));
                    break;

                case 'core_course_get_contents':
                    $courseId = (int)($_POST['courseid'] ?? 0);
                    $this->json($this->courseContents($config, $courseId, $tokenScope));
                    break;

                case 'mod_simplevideotracker_create_activity':
                case 'mod_videotracker_create_activity':
                    $this->json($this->createActivity($config, $_POST, $tokenScope));
                    break;

                case 'mod_simplevideotracker_set_video':
                case 'mod_videotracker_set_video':
                    $this->json($this->setVideo($config, $token, $_POST, $tokenScope));
                    break;

                default:
                    $this->moodleError('invalidrecord', 'Unsupported web service function: ' . $function);
            }
        } catch (\Throwable $e) {
            error_log('[simple_video_learning] Moodle compatibility error: ' . $e->getMessage());
            $this->moodleError('simple_video_learningerror', 'Simple Video Learning compatibility request failed.');
        }
    }

    public function procSimple_video_learningCompatUpload(): void
    {
        $config = (new ConfigModel())->get();
        $token = trim((string)($_POST['token'] ?? ''));
        $tokenScope = $this->authenticateToken((string)$config->api_token_hash, $token);
        if ($tokenScope === false) {
            $this->moodleError('invalidtoken', 'Invalid token');
        }

        $file = $_FILES['file_1'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->moodleError('invalidparameter', 'file_1 upload is required');
        }
        if (!is_uploaded_file((string)$file['tmp_name'])) {
            $this->moodleError('invalidparameter', 'Invalid uploaded file');
        }

        $size = (int)($file['size'] ?? 0);
        $maxUploadBytes = max(1, (int)$config->max_upload_mb) * 1024 * 1024;
        if ($size <= 0 || $size > $maxUploadBytes) {
            $this->moodleError('invalidparameter', 'Uploaded video exceeds the configured size limit');
        }

        $name = basename((string)($file['name'] ?? 'lecture.mp4'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'm4v', 'mov'], true)) {
            $this->moodleError('invalidparameter', 'Only video files are accepted');
        }

        if (!class_exists('finfo')) {
            throw new \RuntimeException('PHP fileinfo extension is required for secure video uploads.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file((string)$file['tmp_name']);
        $allowedMimes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v', 'application/mp4', 'application/quicktime'];
        if (!in_array($mime, $allowedMimes, true)) {
            $this->moodleError('invalidparameter', 'Uploaded file content is not an accepted video type');
        }

        $draftId = (int)getNextSequence();
        $dir = $this->draftDirectory();

        $path = $dir . '/' . $draftId . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $path)) {
            throw new \RuntimeException('Could not store uploaded video.');
        }

        $meta = [
            'draftitemid' => $draftId,
            'filename' => $name,
            'path' => $path,
            'size' => (int)($file['size'] ?? filesize($path)),
            'token_hash' => hash('sha256', $token),
            'created_at' => time(),
        ];
        if (file_put_contents($this->draftMetaPath($draftId), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            @unlink($path);
            throw new \RuntimeException('Could not store upload metadata.');
        }
        $this->cleanupDrafts();

        // Moodle upload.php-compatible minimal response. Moodle-compatible clients read itemid.
        $this->json([[
            'component' => 'user',
            'contextid' => 0,
            'userid' => 0,
            'filearea' => 'draft',
            'filename' => $name,
            'filepath' => '/',
            'itemid' => $draftId,
            'draftitemid' => $draftId,
            'license' => 'allrightsreserved',
            'author' => (string)$config->author_name,
            'source' => $name,
        ]]);
    }

    private function siteInfo(): array
    {
        $functions = [
            'core_webservice_get_site_info',
            'core_enrol_get_users_courses',
            'core_course_get_contents',
            'mod_simplevideotracker_create_activity',
            'mod_simplevideotracker_set_video',
            'mod_videotracker_create_activity',
            'mod_videotracker_set_video',
        ];

        return [
            'sitename' => 'Rhymix / Simple Video Learning',
            'username' => 'simple_video_learning',
            'firstname' => 'AI',
            'lastname' => 'Professor',
            'fullname' => 'Simple Video Learning',
            'lang' => 'ko',
            'userid' => 1,
            'siteurl' => rtrim((string)\Context::getDefaultUrl(), '/'),
            'functions' => array_map(static fn(string $name): array => ['name' => $name, 'version' => '2026100600'], $functions),
        ];
    }

    private function courses(object $config, $tokenScope = null): array
    {
        $courses = CourseModel::getAll();
        if ($tokenScope) {
            $scoped = CourseModel::get((int)$tokenScope->course_srl);
            $courses = $scoped ? [$scoped] : [];
        }

        return array_values(array_map(static function (object $course): array {
            return [
                'id' => (int)$course->course_srl,
                'shortname' => (string)$course->course_code,
                'fullname' => (string)$course->title,
                'displayname' => (string)$course->title,
                'summary' => (string)($course->description ?? ''),
                'visible' => (string)($course->is_published ?? 'Y') === 'Y' ? 1 : 0,
                'startdate' => 0,
                'enddate' => 0,
                'progress' => null,
            ];
        }, $courses));
    }

    private function courseContents(object $config, int $courseId, $tokenScope = null): array
    {
        if ($tokenScope && $courseId !== (int)$tokenScope->course_srl) {
            throw new \RuntimeException('Course token cannot access another course.');
        }

        $course = CourseModel::get($courseId);
        if (!$course) {
            throw new \RuntimeException('Unknown course id.');
        }

        $numSections = max(1, min(99, (int)($course->num_sections ?? 15)));
        $sections = [];
        for ($week = 1; $week <= $numSections; $week++) {
            $sections[$week] = [
                'id' => $week,
                'section' => $week,
                'name' => $week . '주차',
                'summary' => '',
                'visible' => 1,
                'modules' => [],
            ];
        }

        foreach (LectureModel::getByCourse($courseId) as $lecture) {
            $week = max(1, (int)($lecture->week_no ?? 1));
            if ($week > $numSections || !isset($sections[$week])) {
                continue;
            }

            $documentSrl = (int)$lecture->document_srl;
            $doc = DocumentModel::getDocument($documentSrl, false, false);
            if (!$doc || !$doc->isExists()) {
                continue;
            }

            $sections[$week]['modules'][] = [
                'id' => $documentSrl,
                'instance' => $documentSrl,
                'name' => (string)$doc->getTitleText(),
                'modname' => self::COMPONENT,
                'url' => $this->frontendLectureUrl($documentSrl),
                'visible' => 1,
                'availability' => null,
            ];
        }

        return array_values($sections);
    }

    private function createActivity(object $config, array $params, $tokenScope = null): array
    {
        $courseId = (int)($params['courseid'] ?? 0);
        if ($tokenScope) {
            if ($courseId > 0 && $courseId !== (int)$tokenScope->course_srl) {
                throw new \RuntimeException('Course token cannot access another course.');
            }
            $courseId = (int)$tokenScope->course_srl;
        }

        $course = CourseModel::get($courseId);
        if (!$course) {
            throw new \RuntimeException('Unknown course id.');
        }
        $courseSrl = (int)$course->course_srl;
        $moduleInfo = $this->resolveLearningModuleInfo((string)$config->target_mid);

        $weekNo = $this->parseSectionNumber($params['sectionnum'] ?? $params['week_no'] ?? $params['week'] ?? $params['section'] ?? 1);
        if ($weekNo > max(1, (int)($course->num_sections ?? 15))) {
            $expand = CourseModel::save([
                'course_srl' => $courseSrl,
                'course_code' => (string)$course->course_code,
                'title' => (string)$course->title,
                'description' => (string)($course->description ?? ''),
                'num_sections' => $weekNo,
                'is_published' => (string)($course->is_published ?? 'Y'),
            ]);
            if (!$expand->toBool()) {
                throw new \RuntimeException((string)$expand->getMessage());
            }
        }

        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Activity name is required.');
        }

        $intro = removeHackTag((string)($params['intro'] ?? ''));
        $documentSrl = (int)getNextSequence();
        $obj = $this->documentObject(
            $documentSrl,
            (int)$moduleInfo->module_srl,
            $name,
            $intro,
            (string)$config->author_name
        );

        $output = DocumentController::getInstance()->insertDocument($obj, true);
        if (!$output->toBool()) {
            throw new \RuntimeException((string)$output->getMessage());
        }
        $documentSrl = (int)($output->get('document_srl') ?: $documentSrl);

        $map = LectureModel::upsert([
            'external_id' => 'compat-cmid-' . $documentSrl,
            'module_srl' => (int)$moduleInfo->module_srl,
            'course_srl' => $courseSrl,
            'week_no' => $weekNo,
            'document_srl' => $documentSrl,
            'video_url' => '',
            'duration' => 0,
            'completion_threshold' => (int)$config->default_threshold,
        ]);
        if (!$map->toBool()) {
            DocumentController::getInstance()->deleteDocument($documentSrl, true);
            throw new \RuntimeException((string)$map->getMessage());
        }

        return [
            'success' => true,
            'cmid' => $documentSrl,
            'instanceid' => $documentSrl,
            'url' => $this->frontendLectureUrl($documentSrl),
        ];
    }

    private function setVideo(object $config, string $token, array $params, $tokenScope = null): array
    {
        $cmid = (int)($params['cmid'] ?? 0);
        $draftId = (int)($params['draftitemid'] ?? 0);
        $duration = isset($params['duration']) ? (float)$params['duration'] : 0.0;
        if ($cmid <= 0 || $draftId <= 0) {
            throw new \RuntimeException('cmid and draftitemid are required.');
        }
        if (!is_finite($duration) || $duration < 0) {
            throw new \RuntimeException('Invalid duration.');
        }

        $lecture = LectureModel::getByDocument($cmid);
        if (!$lecture) {
            throw new \RuntimeException('Target VideoTracker activity does not exist.');
        }
        if ($tokenScope && (int)($lecture->course_srl ?? 0) !== (int)$tokenScope->course_srl) {
            throw new \RuntimeException('Course token cannot access another course.');
        }
        $moduleInfo = $this->resolveLearningModuleInfo((string)$config->target_mid);

        $meta = $this->loadDraft($draftId, $token);
        if (!is_file((string)$meta['path'])) {
            throw new \RuntimeException('Uploaded draft file is missing.');
        }

        $fileController = FileController::getInstance();
        $oldFiles = FileModel::getFiles($cmid, [], 'file_srl', true, 'doc', true);

        $fileInfo = [
            'name' => (string)$meta['filename'],
            'tmp_name' => (string)$meta['path'],
            'size' => (int)$meta['size'],
            'error' => UPLOAD_ERR_OK,
            'type' => 'application/octet-stream',
        ];
        $insert = $fileController->insertFile($fileInfo, (int)$moduleInfo->module_srl, $cmid, 0, true);
        if (!$insert->toBool()) {
            throw new \RuntimeException((string)$insert->getMessage());
        }
        $fileSrl = (int)$insert->get('file_srl');
        $valid = $fileController->setFilesValid($cmid, 'doc', [$fileSrl]);
        if (!$valid->toBool()) {
            $fileController->deleteFile($fileSrl);
            throw new \RuntimeException((string)$valid->getMessage());
        }

        // Use Rhymix's download route so the course enrollment hook runs.
        $videoUrl = html_entity_decode(
            FileModel::getDownloadUrl($fileSrl, (string)$insert->get('sid'), 0, (string)$insert->get('source_filename')),
            ENT_QUOTES,
            'UTF-8'
        );

        $doc = DocumentModel::getDocument($cmid, false, false);
        if (!$doc || !$doc->isExists()) {
            $fileController->deleteFile($fileSrl);
            throw new \RuntimeException('Rhymix document does not exist.');
        }
        $originalContent = (string)$doc->getContent(false);
        $body = $this->stripPlayer($originalContent);
        $content = $this->buildContent(
            $cmid,
            $body,
            $videoUrl,
            (int)round($duration),
            (int)$lecture->completion_threshold
        );
        $obj = $this->documentObject(
            $cmid,
            (int)$moduleInfo->module_srl,
            (string)$doc->getTitleText(),
            $content,
            (string)$config->author_name
        );
        $update = DocumentController::getInstance()->updateDocument($doc, $obj, true);
        if (!$update->toBool()) {
            $fileController->deleteFile($fileSrl);
            throw new \RuntimeException((string)$update->getMessage());
        }

        $map = LectureModel::upsert([
            'external_id' => (string)$lecture->external_id,
            'module_srl' => (int)$moduleInfo->module_srl,
            'course_srl' => (int)($lecture->course_srl ?? 0),
            'week_no' => (int)($lecture->week_no ?? 1),
            'sort_order' => (int)($lecture->sort_order ?? 0),
            'document_srl' => $cmid,
            'video_url' => $videoUrl,
            'duration' => (int)round($duration),
            'completion_threshold' => (int)$lecture->completion_threshold,
        ]);
        if (!$map->toBool()) {
            $rollback = $this->documentObject(
                $cmid,
                (int)$moduleInfo->module_srl,
                (string)$doc->getTitleText(),
                $originalContent,
                (string)$config->author_name
            );
            DocumentController::getInstance()->updateDocument($doc, $rollback, true);
            $fileController->deleteFile($fileSrl);
            throw new \RuntimeException((string)$map->getMessage());
        }

        foreach ((array)$oldFiles as $oldFile) {
            $oldFileSrl = (int)($oldFile->file_srl ?? 0);
            $oldName = strtolower((string)($oldFile->source_filename ?? $oldFile->uploaded_filename ?? ''));
            $oldExt = strtolower(pathinfo($oldName, PATHINFO_EXTENSION));
            if ($oldFileSrl > 0 && $oldFileSrl !== $fileSrl && in_array($oldExt, ['mp4', 'webm', 'm4v', 'mov'], true)) {
                $fileController->deleteFile($oldFileSrl);
            }
        }

        @unlink($this->draftMetaPath($draftId));
        if (is_file((string)$meta['path'])) {
            @unlink((string)$meta['path']);
        }

        return [
            'success' => true,
            'cmid' => $cmid,
            'file_srl' => $fileSrl,
            'duration' => $duration,
            'video_url' => $videoUrl,
            'url' => $this->frontendLectureUrl($cmid),
        ];
    }

    private function frontendLectureUrl(int $documentSrl): ?string
    {
        $instances = ModuleModel::getMidList((object)['module' => 'simple_video_learning']);
        if ($instances) {
            $first = reset($instances);
            if ($first && !empty($first->mid)) {
                return getFullUrl('', 'mid', (string)$first->mid, 'act', 'dispSimple_video_learningLecture', 'document_srl', $documentSrl);
            }
        }
        return null;
    }

    private function documentObject(int $documentSrl, int $moduleSrl, string $title, string $content, string $author): \stdClass
    {
        $obj = new \stdClass();
        $obj->document_srl = $documentSrl;
        $obj->module_srl = $moduleSrl;
        $obj->category_srl = 0;
        $obj->title = $title;
        $obj->content = $content !== '' ? $content : '<p></p>';
        $obj->nick_name = $author;
        $obj->user_name = $author;
        $obj->member_srl = 0;
        $obj->email_address = '';
        $obj->homepage = '';
        $obj->status = 'PUBLIC';
        $obj->comment_status = 'ALLOW';
        $obj->allow_trackback = 'N';
        $obj->notify_message = 'N';
        return $obj;
    }

    private function buildContent(int $documentSrl, string $body, string $videoUrl, int $duration, int $threshold): string
    {
        $safeUrl = htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8');
        $player = sprintf(
            '<!--SVL_PLAYER_START--><div class="simple_video_learning-lecture" data-document-srl="%d" data-duration="%d" data-threshold="%d"><video class="simple_video_learning-tracked-video" controls preload="metadata" src="%s"></video><div class="simple_video_learning-progress" aria-live="polite"></div></div><!--SVL_PLAYER_END-->',
            $documentSrl,
            $duration,
            $threshold,
            $safeUrl
        );
        return $player . "\n" . removeHackTag(trim($body));
    }

    private function stripPlayer(string $content): string
    {
        return trim((string)preg_replace('/<!--(?:SVL|AIPROF)_PLAYER_START-->.*?<!--(?:SVL|AIPROF)_PLAYER_END-->/is', '', $content));
    }

    private function authenticateToken(string $globalTokenHash, string $provided)
    {
        if ($provided === '') {
            return false;
        }
        if ($globalTokenHash !== '' && hash_equals($globalTokenHash, hash('sha256', $provided))) {
            return null;
        }
        $courseToken = CourseApiTokenModel::authenticate($provided);
        return $courseToken ?: false;
    }

    private function draftDirectory(): string
    {
        $base = realpath((string)\RX_BASEDIR);
        $temp = realpath(sys_get_temp_dir());
        if ($base === false || $temp === false || $temp === $base
            || str_starts_with($temp . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException('A temporary directory outside the Rhymix web root is required.');
        }
        $dir = $temp . '/rhymix_simple_video_learning_' . substr(hash('sha256', $base), 0, 24);
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir))
            || !chmod($dir, 0700) || !is_writable($dir)
        ) {
            throw new \RuntimeException('Cannot create private temporary upload directory.');
        }
        return $dir;
    }

    private function draftMetaPath(int $draftId): string
    {
        return $this->draftDirectory() . '/' . $draftId . '.json';
    }

    private function loadDraft(int $draftId, string $token): array
    {
        $path = $this->draftMetaPath($draftId);
        if (!is_file($path)) {
            throw new \RuntimeException('Draft upload not found.');
        }
        $meta = json_decode((string)file_get_contents($path), true);
        if (!is_array($meta)) {
            throw new \RuntimeException('Draft metadata is invalid.');
        }
        $storedPath = realpath((string)($meta['path'] ?? ''));
        if ($storedPath === false || dirname($storedPath) !== $this->draftDirectory()
            || is_link((string)$meta['path'])
        ) {
            throw new \RuntimeException('Draft file is outside the private upload directory.');
        }
        if (!hash_equals((string)($meta['token_hash'] ?? ''), hash('sha256', $token))) {
            throw new \RuntimeException('Draft upload token mismatch.');
        }
        if ((int)($meta['created_at'] ?? 0) < time() - self::DRAFT_TTL) {
            throw new \RuntimeException('Draft upload has expired.');
        }
        return $meta;
    }

    private function cleanupDrafts(): void
    {
        $dir = $this->draftDirectory();
        foreach ((array)glob($dir . '/*.json') as $metaPath) {
            $meta = json_decode((string)@file_get_contents($metaPath), true);
            if (!is_array($meta) || (int)($meta['created_at'] ?? 0) < time() - self::DRAFT_TTL) {
                if (is_array($meta) && !empty($meta['path']) && dirname((string)$meta['path']) === $dir
                    && !is_link((string)$meta['path']) && is_file((string)$meta['path'])
                ) {
                    @unlink((string)$meta['path']);
                }
                @unlink($metaPath);
            }
        }
    }

    private function moodleError(string $errorCode, string $message): void
    {
        $this->json([
            'exception' => 'moodle_exception',
            'errorcode' => $errorCode,
            'message' => $message,
        ]);
    }

    private function json($data): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        \Context::close();
        exit;
    }
}
