<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use Context;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\EnrollmentModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

class EnrollmentController extends ModuleBase
{
    public function procSimple_video_learningEnroll()
    {
        $loggedInfo = Context::get('logged_info');
        if (!$loggedInfo || empty($loggedInfo->member_srl)) {
            throw new \Rhymix\Framework\Exceptions\MustLogin();
        }

        $this->ensureDatabaseReady(($loggedInfo->is_admin ?? 'N') === 'Y');

        $courseSrl = (int)Context::get('course_srl');
        $course = CourseModel::get($courseSrl);
        if (!$course || (string)($course->is_published ?? 'Y') !== 'Y') {
            return new \BaseObject(-1, '수업을 찾을 수 없습니다.');
        }

        $mode = (string)($course->enrollment_mode ?? ((string)($course->enrollment_enabled ?? 'Y') === 'N' ? 'open' : 'auto'));
        if (EnrollmentModel::getStatus($courseSrl, (int)$loggedInfo->member_srl) === EnrollmentModel::SUSPENDED) {
            return new \BaseObject(-1, '수강이 정지되어 있습니다. 교수 또는 조교에게 문의하세요.');
        }
        if ($mode === 'open') {
            $this->setMessage('별도 수강신청 없이 학습할 수 있는 수업입니다.');
            $this->setRedirectUrl(Context::get('success_return_url'));
            return;
        }
        if ($mode === 'closed') {
            return new \BaseObject(-1, '현재 직접 수강신청을 받지 않는 수업입니다.');
        }

        $autoApprove = $mode === 'auto';
        $output = EnrollmentModel::request($courseSrl, (int)$loggedInfo->member_srl, $autoApprove);
        if (!$output->toBool()) {
            return $output;
        }

        $this->setMessage($autoApprove ? '수강신청이 완료되었습니다.' : '수강신청이 접수되었습니다. 운영자 승인을 기다려 주세요.');
        $this->setRedirectUrl(
            Context::get('success_return_url')
            ?: getNotEncodedUrl('', 'mid', (string)$this->mid, 'act', 'dispSimple_video_learningCourse', 'course_srl', $courseSrl)
        );
    }

    public function procSimple_video_learningUnenroll()
    {
        $loggedInfo = Context::get('logged_info');
        if (!$loggedInfo || empty($loggedInfo->member_srl)) {
            throw new \Rhymix\Framework\Exceptions\MustLogin();
        }

        $this->ensureDatabaseReady(($loggedInfo->is_admin ?? 'N') === 'Y');

        $courseSrl = (int)Context::get('course_srl');
        $output = EnrollmentModel::withdraw($courseSrl, (int)$loggedInfo->member_srl);
        if (!$output->toBool()) {
            return $output;
        }

        $this->setMessage('수강취소되었습니다.');
        $this->setRedirectUrl(Context::get('success_return_url') ?: getNotEncodedUrl('', 'mid', (string)$this->mid));
    }
}
