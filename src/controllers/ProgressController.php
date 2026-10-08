<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use Context;
use DocumentModel;
use ModuleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseRoleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\EnrollmentModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\LectureModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\ProgressModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

class ProgressController extends ModuleBase
{
    public function procSimple_video_learningProgress(): void
    {
        Context::setResponseMethod('JSON');

        $loggedInfo = Context::get('logged_info');
        if (!$loggedInfo || empty($loggedInfo->member_srl)) {
            throw new \Rhymix\Framework\Exceptions\MustLogin();
        }
        $documentSrl = (int)Context::get('document_srl');
        $lecture = LectureModel::getByDocument($documentSrl);
        if (!$lecture) {
            throw new \Rhymix\Framework\Exceptions\TargetNotFound();
        }

        $courseSrl = (int)($lecture->course_srl ?? 0);
        $accessModuleSrl = max(0, (int)Context::get('access_module_srl'));

        if ($courseSrl > 0) {
            $course = CourseModel::get($courseSrl);
            if (!$course || (string)($course->is_published ?? 'Y') !== 'Y') {
                throw new \Rhymix\Framework\Exceptions\NotPermitted();
            }

            $privileged = (($loggedInfo->is_admin ?? 'N') === 'Y');
            if ($accessModuleSrl > 0) {
                $accessModule = ModuleModel::getModuleInfoByModuleSrl($accessModuleSrl);
                if (!$accessModule || (string)($accessModule->module ?? '') !== 'simple_video_learning') {
                    throw new \Rhymix\Framework\Exceptions\NotPermitted();
                }
                $accessGrant = ModuleModel::getGrant($accessModule, $loggedInfo);
                if (empty($accessGrant->access) || (isset($accessGrant->view) && !$accessGrant->view)) {
                    throw new \Rhymix\Framework\Exceptions\NotPermitted();
                }
                $privileged = $privileged || !empty($accessGrant->manager) || !empty($accessGrant->root);
            } else {
                $sourceModule = ModuleModel::getModuleInfoByModuleSrl((int)($lecture->module_srl ?? 0));
                if ($sourceModule && (string)($sourceModule->module ?? '') === 'simple_video_learning') {
                    $sourceGrant = ModuleModel::getGrant($sourceModule, $loggedInfo);
                    if (empty($sourceGrant->access) || (isset($sourceGrant->view) && !$sourceGrant->view)) {
                        throw new \Rhymix\Framework\Exceptions\NotPermitted();
                    }
                    $privileged = $privileged || !empty($sourceGrant->manager) || !empty($sourceGrant->root);
                }
            }

            $mode = (string)($course->enrollment_mode ?? ((string)($course->enrollment_enabled ?? 'Y') === 'N' ? 'open' : 'auto'));
            $privileged = $privileged || CourseRoleModel::isStaff($courseSrl, (int)$loggedInfo->member_srl);

            if (!$privileged
                && EnrollmentModel::getStatus($courseSrl, (int)$loggedInfo->member_srl) === EnrollmentModel::SUSPENDED
            ) {
                throw new \Rhymix\Framework\Exceptions\NotPermitted('수강이 정지되어 있습니다.');
            }

            if ($mode !== 'open'
                && !$privileged
                && !EnrollmentModel::isEnrolled($courseSrl, (int)$loggedInfo->member_srl)
            ) {
                throw new \Rhymix\Framework\Exceptions\NotPermitted('수강신청 후 학습할 수 있습니다.');
            }
        } else {
            // Legacy board-backed lectures keep the original Rhymix board grant.
            $moduleSrl = (int)($lecture->module_srl ?? 0);
            if ($moduleSrl > 0) {
                $moduleInfo = ModuleModel::getModuleInfoByModuleSrl($moduleSrl);
                if (!$moduleInfo) {
                    throw new \Rhymix\Framework\Exceptions\TargetNotFound();
                }

                $grant = ModuleModel::getGrant($moduleInfo, $loggedInfo);
                if (empty($grant->access) || (isset($grant->view) && !$grant->view)) {
                    throw new \Rhymix\Framework\Exceptions\NotPermitted();
                }

                $document = DocumentModel::getDocument($documentSrl, false, false);
                if (!$document || !$document->isExists() || !$document->isAccessible()) {
                    throw new \Rhymix\Framework\Exceptions\NotPermitted();
                }
            }
        }

        $duration = max(0, (int)$lecture->duration);
        // Lecture metadata is supplied by an authorized publisher, never by a
        // learner heartbeat. Unknown durations cannot earn completion credit.

        $currentTime = max(0, (int)floor((float)Context::get('current_time')));
        $playing = Context::get('playing') === 'Y';

        $result = ProgressModel::record(
            $documentSrl,
            (int)$loggedInfo->member_srl,
            $currentTime,
            $duration,
            $playing,
            (int)$lecture->completion_threshold
        );

        foreach ($result as $key => $value) {
            $this->add($key, $value);
        }
        $this->add('duration_known', $duration > 0);
    }
}
