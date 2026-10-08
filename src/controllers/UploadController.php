<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use Context;
use DocumentController;
use DocumentModel;
use FileController;
use FileModel;
use FileHandler;
use Rhymix\Framework\Session;
use Rhymix\Modules\Simple_video_learning\Src\Models\ConfigModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseRoleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\LectureModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

class UploadController extends ModuleBase
{
    public function dispSimple_video_learningUpload(): void
    {
        $loggedInfo = Context::get('logged_info');
        if (!$loggedInfo || empty($loggedInfo->member_srl)) {
            throw new \Rhymix\Framework\Exceptions\MustLogin();
        }

        $this->ensureDatabaseReady(($loggedInfo->is_admin ?? 'N') === 'Y');

        $memberSrl = (int)$loggedInfo->member_srl;
        $globalUpload = (($loggedInfo->is_admin ?? 'N') === 'Y')
            || !empty($this->grant->upload)
            || !empty($this->grant->manager)
            || !empty($this->grant->root);

        $courses = array_values(array_filter(
            CourseModel::getAll(),
            static function (object $course) use ($globalUpload, $memberSrl): bool {
                return $globalUpload || CourseRoleModel::canEditActivities((int)$course->course_srl, $memberSrl);
            }
        ));


        $config = (new ConfigModel())->get();
        $maxBytes = max(1, (int)$config->max_upload_mb) * 1024 * 1024;
        foreach ([ini_get('upload_max_filesize'), ini_get('post_max_size')] as $iniLimit) {
            $bytes = FileHandler::returnBytes((string)$iniLimit);
            if ($bytes > 0) {
                $maxBytes = min($maxBytes, $bytes);
            }
        }

        Context::set('simple_video_learning_courses', $courses);
        Context::set('simple_video_learning_config', $config);
        Context::set('simple_video_learning_effective_upload_bytes', $maxBytes);
        Context::set('simple_video_learning_effective_upload_mb', max(1, (int)floor($maxBytes / 1024 / 1024)));
        Context::set('simple_video_learning_csrf_token', Session::getGenericToken());
        $this->useFrontendSkin('upload');
    }

    public function procSimple_video_learningUpload()
    {
        $loggedInfo = Context::get('logged_info');
        if (!$loggedInfo || empty($loggedInfo->member_srl)) {
            throw new \Rhymix\Framework\Exceptions\MustLogin();
        }

        $this->ensureDatabaseReady(($loggedInfo->is_admin ?? 'N') === 'Y');

        $courseSrl = (int)Context::get('course_srl');
        $course = CourseModel::get($courseSrl);
        if (!$course) {
            return new \BaseObject(-1, '수업을 찾을 수 없습니다.');
        }

        $globalUpload = (($loggedInfo->is_admin ?? 'N') === 'Y')
            || !empty($this->grant->upload)
            || !empty($this->grant->manager)
            || !empty($this->grant->root);
        if (!$globalUpload && !CourseRoleModel::canEditActivities($courseSrl, (int)$loggedInfo->member_srl)) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted('이 수업의 강의를 업로드할 권한이 없습니다.');
        }

        $weekNo = max(1, (int)Context::get('week_no'));
        $numSections = max(1, min(99, (int)($course->num_sections ?? 15)));
        if ($weekNo > $numSections) {
            return new \BaseObject(-1, '존재하지 않는 섹션입니다.');
        }

        $title = trim((string)Context::get('title'));
        $description = trim((string)Context::get('description'));
        $sortOrder = max(0, (int)Context::get('sort_order'));
        $duration = max(0, (int)round((float)Context::get('duration')));
        if ($title === '') {
            return new \BaseObject(-1, '강의 제목을 입력하세요.');
        }
        if ($duration <= 0) {
            return new \BaseObject(-1, '영상 재생시간을 확인할 수 없습니다. 파일을 다시 선택해 주세요.');
        }

        $config = (new ConfigModel())->get();
        $maxBytes = max(1, (int)$config->max_upload_mb) * 1024 * 1024;
        foreach ([ini_get('upload_max_filesize'), ini_get('post_max_size')] as $iniLimit) {
            $bytes = FileHandler::returnBytes((string)$iniLimit);
            if ($bytes > 0) {
                $maxBytes = min($maxBytes, $bytes);
            }
        }

        $contentLength = max(0, (int)($_SERVER['CONTENT_LENGTH'] ?? 0));
        if ($contentLength > 0 && $contentLength > $maxBytes) {
            return new \BaseObject(-1, '서버 업로드 한도를 초과했습니다. 현재 허용 크기: 약 ' . max(1, (int)floor($maxBytes / 1024 / 1024)) . 'MB');
        }

        $file = $_FILES['video_file'] ?? null;
        if (!is_array($file)) {
            return new \BaseObject(-1, '업로드된 영상 파일을 받지 못했습니다. PHP upload_max_filesize/post_max_size 설정을 확인하세요.');
        }

        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'PHP upload_max_filesize 제한을 초과했습니다.',
                UPLOAD_ERR_FORM_SIZE => '폼에서 허용한 파일 크기를 초과했습니다.',
                UPLOAD_ERR_PARTIAL => '영상 파일이 일부만 업로드되었습니다.',
                UPLOAD_ERR_NO_FILE => '영상 파일을 선택하세요.',
                UPLOAD_ERR_NO_TMP_DIR => '서버 임시 업로드 디렉터리가 없습니다.',
                UPLOAD_ERR_CANT_WRITE => '서버가 업로드 파일을 저장하지 못했습니다.',
                UPLOAD_ERR_EXTENSION => 'PHP 확장 기능이 업로드를 중단했습니다.',
            ];
            return new \BaseObject(-1, $messages[$uploadError] ?? ('영상 업로드 오류 코드: ' . $uploadError));
        }

        if (!is_uploaded_file((string)$file['tmp_name'])) {
            return new \BaseObject(-1, '올바른 업로드 파일이 아닙니다.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            return new \BaseObject(-1, '업로드 가능한 최대 영상 크기를 초과했습니다. 현재 허용 크기: 약 ' . max(1, (int)floor($maxBytes / 1024 / 1024)) . 'MB');
        }

        $name = basename((string)($file['name'] ?? 'lecture.mp4'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'm4v', 'mov'], true)) {
            return new \BaseObject(-1, 'MP4, WebM, M4V, MOV 영상만 업로드할 수 있습니다.');
        }

        if (!class_exists('finfo')) {
            return new \BaseObject(-1, 'PHP fileinfo 확장이 필요합니다.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file((string)$file['tmp_name']);
        $allowedMimes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v', 'application/mp4', 'application/quicktime'];
        if (!in_array($mime, $allowedMimes, true)) {
            return new \BaseObject(-1, '영상 파일 형식을 확인할 수 없습니다.');
        }

        $documentSrl = (int)getNextSequence();
        $obj = new \stdClass();
        $obj->document_srl = $documentSrl;
        $obj->module_srl = (int)$this->module_srl;
        $obj->category_srl = 0;
        $obj->title = $title;
        $obj->content = $description !== '' ? nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')) : '<p></p>';
        $obj->member_srl = (int)$loggedInfo->member_srl;
        $obj->nick_name = (string)($loggedInfo->nick_name ?? '');
        $obj->user_name = (string)($loggedInfo->user_name ?? '');
        $obj->email_address = (string)($loggedInfo->email_address ?? '');
        $obj->homepage = (string)($loggedInfo->homepage ?? '');
        $obj->status = 'PUBLIC';
        $obj->comment_status = 'DENY';
        $obj->allow_trackback = 'N';
        $obj->notify_message = 'N';

        $documentController = DocumentController::getInstance();
        $insertDoc = $documentController->insertDocument($obj, true);
        if (!$insertDoc->toBool()) {
            return $insertDoc;
        }
        $documentSrl = (int)($insertDoc->get('document_srl') ?: $documentSrl);

        $fileController = FileController::getInstance();
        $fileInfo = [
            'name' => $name,
            'tmp_name' => (string)$file['tmp_name'],
            'size' => $size,
            'error' => UPLOAD_ERR_OK,
            'type' => $mime,
        ];

        $insertFile = $fileController->insertFile($fileInfo, (int)$this->module_srl, $documentSrl, 0, true);
        if (!$insertFile->toBool()) {
            $documentController->deleteDocument($documentSrl, true);
            return $insertFile;
        }

        $fileSrl = (int)$insertFile->get('file_srl');
        $valid = $fileController->setFilesValid($documentSrl, 'doc', [$fileSrl]);
        if (!$valid->toBool()) {
            $fileController->deleteFile($fileSrl);
            $documentController->deleteDocument($documentSrl, true);
            return $valid;
        }

        // Use Rhymix's download route so the course enrollment hook runs.
        $videoUrl = html_entity_decode(
            FileModel::getDownloadUrl($fileSrl, (string)$insertFile->get('sid'), 0, (string)$insertFile->get('source_filename')),
            ENT_QUOTES,
            'UTF-8'
        );

        $doc = DocumentModel::getDocument($documentSrl, false, false);
        if (!$doc || !$doc->isExists()) {
            $fileController->deleteFile($fileSrl);
            return new \BaseObject(-1, '생성된 강의 문서를 찾을 수 없습니다.');
        }

        $safeUrl = htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8');
        $body = sprintf(
            '<!--AIPROF_PLAYER_START--><div class="simple_video_learning-lecture" data-document-srl="%d" data-duration="%d" data-threshold="%d"><video class="simple_video_learning-tracked-video" controls preload="metadata" src="%s"></video><div class="simple_video_learning-progress" aria-live="polite"></div></div><!--AIPROF_PLAYER_END--><div class="simple_video_learning-description">%s</div>',
            $documentSrl,
            $duration,
            (int)$config->default_threshold,
            $safeUrl,
            $description !== '' ? nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')) : ''
        );

        $updateObj = clone $obj;
        $updateObj->document_srl = $documentSrl;
        $updateObj->content = $body;
        $update = $documentController->updateDocument($doc, $updateObj, true);
        if (!$update->toBool()) {
            $fileController->deleteFile($fileSrl);
            $documentController->deleteDocument($documentSrl, true);
            return $update;
        }

        $externalId = 'user-' . (int)$loggedInfo->member_srl . '-' . $documentSrl;
        $map = LectureModel::upsert([
            'external_id' => $externalId,
            'module_srl' => (int)$this->module_srl,
            'course_srl' => $courseSrl,
            'week_no' => $weekNo,
            'sort_order' => $sortOrder,
            'uploader_srl' => (int)$loggedInfo->member_srl,
            'document_srl' => $documentSrl,
            'video_url' => $videoUrl,
            'duration' => $duration,
            'completion_threshold' => (int)$config->default_threshold,
        ]);
        if (!$map->toBool()) {
            $fileController->deleteFile($fileSrl);
            $documentController->deleteDocument($documentSrl, true);
            return $map;
        }

        $this->setMessage('success_registed');
        $this->setRedirectUrl(getNotEncodedUrl('', 'mid', (string)$this->mid, 'act', 'dispSimple_video_learningLecture', 'document_srl', $documentSrl));
    }
}
