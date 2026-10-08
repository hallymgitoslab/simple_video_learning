<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use Context;
use DocumentController;
use DocumentModel;
use ModuleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\ConfigModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseApiTokenModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\LectureModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

class ApiController extends ModuleBase
{
    public function procSimple_video_learningApiUpsertLecture(): void
    {
        $config = (new ConfigModel())->get();
        $tokenScope = $this->authorize((string)$config->api_token_hash);

        $payload = $this->readJsonBody();

        $externalId = trim((string)($payload['external_id'] ?? ''));
        $title = trim((string)($payload['title'] ?? ''));
        $body = (string)($payload['content'] ?? '');
        $videoUrl = trim((string)($payload['video_url'] ?? ''));
        $duration = max(0, (int)($payload['duration'] ?? 0));
        $threshold = max(1, min(100, (int)($payload['completion_threshold'] ?? $config->default_threshold)));
        try {
            $weekNo = $this->parseSectionNumber($payload['sectionnum'] ?? $payload['week_no'] ?? 1);
        } catch (\InvalidArgumentException $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
        $sortOrder = max(0, (int)($payload['sort_order'] ?? 0));

        $courseSrl = max(0, (int)($payload['course_srl'] ?? 0));
        $courseCode = trim((string)($payload['course_code'] ?? ''));
        $existing = $externalId !== '' ? LectureModel::getByExternalId($externalId) : null;

        if ($tokenScope) {
            $scopedCourseSrl = (int)$tokenScope->course_srl;
            if ($courseSrl > 0 && $courseSrl !== $scopedCourseSrl) {
                $this->json(['success' => false, 'error' => 'course token cannot access another course'], 403);
            }
            if ($courseCode !== '') {
                $requestedCourse = CourseModel::getByCode($courseCode);
                if (!$requestedCourse || (int)$requestedCourse->course_srl !== $scopedCourseSrl) {
                    $this->json(['success' => false, 'error' => 'course token cannot access another course'], 403);
                }
            }
            $courseSrl = $scopedCourseSrl;
        } elseif ($courseSrl <= 0 && $courseCode !== '') {
            $course = CourseModel::getByCode($courseCode);
            $courseSrl = $course ? (int)$course->course_srl : 0;
        } elseif ($courseSrl <= 0 && $existing && (int)($existing->course_srl ?? 0) > 0) {
            // Preserve the existing course when updating a lecture with the global token.
            $courseSrl = (int)$existing->course_srl;
        }

        if ($courseSrl <= 0) {
            $this->json(['success' => false, 'error' => 'course_srl or course_code is required for LMS lectures'], 422);
        }

        if ($courseSrl > 0) {
            $course = CourseModel::get($courseSrl);
            if (!$course) {
                $this->json(['success' => false, 'error' => 'course_srl/course_code does not identify a course'], 422);
            }
            $numSections = max(1, (int)($course->num_sections ?? 15));
            if ($weekNo > $numSections) {
                $output = CourseModel::save([
                    'course_srl' => $courseSrl,
                    'course_code' => (string)$course->course_code,
                    'title' => (string)$course->title,
                    'description' => (string)($course->description ?? ''),
                    'num_sections' => $weekNo,
                    'is_published' => (string)($course->is_published ?? 'Y'),
                ]);
                if (!$output->toBool()) {
                    $this->json(['success' => false, 'error' => 'course section count could not be expanded'], 500);
                }
            }
        }

        if ($externalId === '' || $title === '' || $videoUrl === '') {
            $this->json(['success' => false, 'error' => 'external_id, title, video_url are required'], 422);
        }

        if (!preg_match('#^https?://#i', $videoUrl)) {
            $this->json(['success' => false, 'error' => 'video_url must use http or https'], 422);
        }

        try {
            $moduleInfo = $this->resolveLearningModuleInfo((string)($payload['target_mid'] ?? $config->target_mid));
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 503);
        }

        if ($tokenScope && $existing && (int)($existing->course_srl ?? 0) !== (int)$tokenScope->course_srl) {
            $this->json(['success' => false, 'error' => 'course token cannot update a lecture in another course'], 403);
        }
        $documentSrl = $existing ? (int)$existing->document_srl : getNextSequence();

        $obj = new \stdClass();
        $obj->document_srl = $documentSrl;
        $obj->module_srl = (int)$moduleInfo->module_srl;
        $obj->category_srl = 0;
        $obj->title = $title;
        $obj->content = $this->buildContent($documentSrl, $body, $videoUrl, $duration, $threshold);
        $obj->nick_name = (string)$config->author_name;
        $obj->user_name = (string)$config->author_name;
        $obj->member_srl = 0;
        $obj->email_address = '';
        $obj->homepage = '';
        $obj->status = 'PUBLIC';
        $obj->comment_status = 'ALLOW';
        $obj->allow_trackback = 'N';
        $obj->notify_message = 'N';

        $controller = DocumentController::getInstance();
        if ($existing) {
            $source = DocumentModel::getDocument($documentSrl, false, false);
            if (!$source || !$source->isExists()) {
                $this->json(['success' => false, 'error' => 'mapped Rhymix document no longer exists'], 409);
            }
            $output = $controller->updateDocument($source, $obj, true);
        } else {
            $output = $controller->insertDocument($obj, true);
            if ($output->toBool()) {
                $documentSrl = (int)$output->get('document_srl');
            }
        }

        if (!$output->toBool()) {
            $this->json(['success' => false, 'error' => $output->getMessage()], 500);
        }

        $mapOutput = LectureModel::upsert([
            'external_id' => $externalId,
            'module_srl' => (int)$moduleInfo->module_srl,
            'course_srl' => $courseSrl,
            'week_no' => $weekNo,
            'sort_order' => $sortOrder,
            'document_srl' => $documentSrl,
            'video_url' => $videoUrl,
            'duration' => $duration,
            'completion_threshold' => $threshold,
        ]);
        if (!$mapOutput->toBool()) {
            if (!$existing) {
                DocumentController::getInstance()->deleteDocument($documentSrl, true);
                $winner = LectureModel::getByExternalId($externalId);
                if ($winner) {
                    $winnerCourseSrl = (int)($winner->course_srl ?? 0);
                    $winnerModule = ModuleModel::getModuleInfoByModuleSrl((int)$winner->module_srl);
                    $winnerUrl = $this->frontendLectureUrl((int)$winner->document_srl)
                        ?: ($winnerModule ? getFullUrl('', 'mid', $winnerModule->mid, 'act', 'dispSimple_video_learningLecture', 'document_srl', (int)$winner->document_srl) : null);
                    $this->json([
                        'success' => true,
                        'external_id' => $externalId,
                        'platform_id' => (int)$winner->document_srl,
                        'document_srl' => (int)$winner->document_srl,
                        'url' => $winnerUrl,
                        'created' => false,
                    ]);
                }
            }
            $this->json(['success' => false, 'error' => 'lecture mapping could not be saved'], 500);
        }

        $url = $this->frontendLectureUrl($documentSrl)
            ?: getFullUrl('', 'mid', (string)$moduleInfo->mid, 'act', 'dispSimple_video_learningLecture', 'document_srl', $documentSrl);
        $this->json([
            'success' => true,
            'external_id' => $externalId,
            'platform_id' => $documentSrl,
            'document_srl' => $documentSrl,
            'url' => $url,
            'created' => !$existing,
        ]);
    }

    public function dispSimple_video_learningApiLecture(): void
    {
        $config = (new ConfigModel())->get();
        $tokenScope = $this->authorize((string)$config->api_token_hash);

        $externalId = trim((string)Context::get('external_id'));
        if ($externalId === '') {
            $this->json(['success' => false, 'error' => 'external_id is required'], 422);
        }

        $lecture = LectureModel::getByExternalId($externalId);
        if (!$lecture) {
            $this->json(['success' => false, 'error' => 'lecture not found'], 404);
        }
        if ($tokenScope && (int)($lecture->course_srl ?? 0) !== (int)$tokenScope->course_srl) {
            $this->json(['success' => false, 'error' => 'course token cannot access another course'], 403);
        }

        $moduleInfo = ModuleModel::getModuleInfoByModuleSrl((int)$lecture->module_srl);
        $this->json([
            'success' => true,
            'lecture' => [
                'external_id' => $lecture->external_id,
                'document_srl' => (int)$lecture->document_srl,
                'course_srl' => (int)($lecture->course_srl ?? 0),
                'sectionnum' => (int)($lecture->week_no ?? 1),
                'sort_order' => (int)($lecture->sort_order ?? 0),
                'video_url' => $lecture->video_url,
                'duration' => (int)$lecture->duration,
                'completion_threshold' => (int)$lecture->completion_threshold,
                'url' => (int)($lecture->course_srl ?? 0) > 0
                    ? $this->frontendLectureUrl((int)$lecture->document_srl)
                    : ($moduleInfo ? getFullUrl('', 'mid', $moduleInfo->mid, 'document_srl', (int)$lecture->document_srl) : null),
            ],
        ]);
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

    private function buildContent(int $documentSrl, string $body, string $videoUrl, int $duration, int $threshold): string
    {
        $safeUrl = htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8');
        $player = sprintf(
            '<div class="simple_video_learning-lecture" data-document-srl="%d" data-duration="%d" data-threshold="%d"><video class="simple_video_learning-tracked-video" controls preload="metadata" src="%s"></video><div class="simple_video_learning-progress" aria-live="polite"></div></div>',
            $documentSrl,
            $duration,
            $threshold,
            $safeUrl
        );

        return $player . "\n" . removeHackTag($body);
    }

    private function authorize(string $globalTokenHash): ?object
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $this->json(['success' => false, 'error' => 'missing bearer token'], 401);
        }

        $provided = trim($matches[1]);
        if ($globalTokenHash !== '' && $provided !== '' && hash_equals($globalTokenHash, hash('sha256', $provided))) {
            return null;
        }

        $courseToken = CourseApiTokenModel::authenticate($provided);
        if ($courseToken) {
            return $courseToken;
        }

        $this->json(['success' => false, 'error' => 'invalid bearer token'], 401);
        return null;
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->json(['success' => false, 'error' => 'invalid JSON body'], 400);
        }
        return $decoded;
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Context::close();
        exit;
    }
}
