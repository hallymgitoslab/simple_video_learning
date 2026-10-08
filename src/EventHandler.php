<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src;

use Context;
use ModuleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseRoleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\EnrollmentModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\LectureModel;

class EventHandler extends ModuleBase
{
    public static function beforeDownloadFile(object $file): void
    {
        if ((string)($file->upload_target_type ?? 'doc') !== 'doc') {
            return;
        }
        $lecture = LectureModel::getByDocument((int)($file->upload_target_srl ?? 0));
        if (!$lecture || (int)($lecture->course_srl ?? 0) <= 0) {
            return;
        }
        $member = Context::get('logged_info');
        if (!$member || empty($member->member_srl)) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }
        $courseSrl = (int)$lecture->course_srl;
        $course = CourseModel::get($courseSrl);
        if (!$course) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }
        $source = ModuleModel::getModuleInfoByModuleSrl((int)$lecture->module_srl);
        $grant = $source ? ModuleModel::getGrant($source, $member) : null;
        if (($member->is_admin ?? 'N') === 'Y'
            || CourseRoleModel::isStaff($courseSrl, (int)$member->member_srl)
            || ($grant && (!empty($grant->manager) || !empty($grant->root)))
        ) {
            return;
        }
        if (!$grant || empty($grant->access) || (isset($grant->view) && !$grant->view)
            || (string)($course->is_published ?? 'Y') !== 'Y'
            || EnrollmentModel::getStatus($courseSrl, (int)$member->member_srl) === EnrollmentModel::SUSPENDED
        ) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }
        $mode = (string)($course->enrollment_mode ?? ((string)($course->enrollment_enabled ?? 'Y') === 'N' ? 'open' : 'auto'));
        if ($mode !== 'open' && !EnrollmentModel::isEnrolled($courseSrl, (int)$member->member_srl)) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }
    }

    public static function afterMenuModuleList(array &$modules): void
    {
        if (!in_array('simple_video_learning', $modules, true)) {
            $modules[] = 'simple_video_learning';
        }
    }

    public static function afterDeleteDocument(object $obj): void
    {
        $documentSrl = (int)($obj->document_srl ?? 0);
        if ($documentSrl <= 0 || !LectureModel::getByDocument($documentSrl)) {
            return;
        }

        executeQuery('simple_video_learning.deleteProgressByDocument', ['document_srl' => $documentSrl]);
        executeQuery('simple_video_learning.deleteLectureByDocument', ['document_srl' => $documentSrl]);
    }
}
