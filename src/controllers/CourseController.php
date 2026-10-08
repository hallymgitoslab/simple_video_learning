<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use Context;
use DocumentModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseRoleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\EnrollmentModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\LectureModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\ProgressModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

class CourseController extends ModuleBase
{
    public function dispSimple_video_learningCourses(): void
    {
        $rawLoggedInfo = Context::get('logged_info');
        $loggedInfo = is_object($rawLoggedInfo) ? $rawLoggedInfo : null;
        $isLoggedIn = (bool)($loggedInfo && !empty($loggedInfo->member_srl));
        $allCourses = CourseModel::getAllSafely();

        if (!$isLoggedIn) {
            // Guest rendering must not depend on member, role, enrollment, or session state.
            $courses = array_values(array_filter(
                $allCourses,
                static fn(object $course): bool => (string)($course->is_published ?? 'Y') === 'Y'
            ));
            foreach ($courses as $course) {
                $course->is_staff_managed = false;
                $course->enrollment_mode = $this->enrollmentMode($course);
                $course->enrollment_required = $course->enrollment_mode !== 'open';
                $course->enrollment_status = null;
                $course->is_enrolled = false;
                $course->can_learn = false;
            }
        } else {
            $courses = array_values(array_filter(
                $allCourses,
                function (object $course) use ($loggedInfo): bool {
                    if ((string)($course->is_published ?? 'Y') === 'Y') {
                        return true;
                    }
                    return $this->isPrivileged($loggedInfo)
                        || CourseRoleModel::isStaff((int)$course->course_srl, (int)$loggedInfo->member_srl);
                }
            ));

            foreach ($courses as $course) {
                $course->is_staff_managed = $this->canManageCourse((int)$course->course_srl, $loggedInfo);
                $course->enrollment_mode = $this->enrollmentMode($course);
                $course->enrollment_required = $course->enrollment_mode !== 'open';
                $course->enrollment_status = EnrollmentModel::getStatus((int)$course->course_srl, (int)$loggedInfo->member_srl);
                $course->is_enrolled = $course->enrollment_status === EnrollmentModel::APPROVED;
                $course->can_learn = $this->canLearn($course, $loggedInfo);
            }
        }

        Context::set('simple_video_learning_courses', $courses);
        Context::set('simple_video_learning_logged_in', $isLoggedIn);
        $this->useFrontendSkin('list');
    }

    public function dispSimple_video_learningCourse(): void
    {
        $rawLoggedInfo = Context::get('logged_info');
        $loggedInfo = is_object($rawLoggedInfo) ? $rawLoggedInfo : null;
        $isLoggedIn = (bool)($loggedInfo && !empty($loggedInfo->member_srl));

        $courseSrl = (int)Context::get('course_srl');
        $course = CourseModel::get($courseSrl);
        if (!$course) {
            throw new \Rhymix\Framework\Exceptions\TargetNotFound();
        }

        if (!$isLoggedIn) {
            if ((string)($course->is_published ?? 'Y') !== 'Y') {
                throw new \Rhymix\Framework\Exceptions\TargetNotFound();
            }
            $enrollmentStatus = null;
            $isEnrolled = false;
            $canLearn = false;
            $canManageCourse = false;
        } else {
            $staffPreview = $this->canManageCourse($courseSrl, $loggedInfo);
            if ((string)($course->is_published ?? 'Y') !== 'Y' && !$staffPreview) {
                throw new \Rhymix\Framework\Exceptions\TargetNotFound();
            }

            $enrollmentStatus = EnrollmentModel::getStatus($courseSrl, (int)$loggedInfo->member_srl);
            $isEnrolled = $enrollmentStatus === EnrollmentModel::APPROVED;
            $canLearn = $this->canLearn($course, $loggedInfo);
            $canManageCourse = $this->canManageCourse($courseSrl, $loggedInfo);
        }

        $courseLectures = LectureModel::getByCourseSafely($courseSrl);
        $numSections = max(1, min(99, (int)($course->num_sections ?? 15)));

        $weeks = [];
        for ($sectionNo = 1; $sectionNo <= $numSections; $sectionNo++) {
            $section = new \stdClass();
            $section->week_no = $sectionNo;
            $section->title = $sectionNo . '주차';
            $section->description = '';
            $section->lectures = [];
            $weeks[$sectionNo] = $section;
        }

        foreach ($courseLectures as $lecture) {
            $sectionNo = max(1, (int)($lecture->week_no ?? 1));
            if ($sectionNo > $numSections) {
                continue;
            }
            $documentSrl = (int)$lecture->document_srl;
            $doc = $documentSrl > 0 ? DocumentModel::getDocument($documentSrl, false, false) : null;
            $lecture->display_title = ($doc && $doc->isExists())
                ? (string)$doc->getTitleText()
                : (string)$lecture->external_id;
            $weeks[$sectionNo]->lectures[] = $lecture;
        }

        Context::set('simple_video_learning_course', $course);
        Context::set('simple_video_learning_weeks', $weeks);
        Context::set('simple_video_learning_logged_in', $isLoggedIn);
        Context::set('simple_video_learning_is_enrolled', $isEnrolled);
        Context::set('simple_video_learning_enrollment_status', $enrollmentStatus);
        Context::set('simple_video_learning_can_learn', $canLearn);
        Context::set('simple_video_learning_can_manage_course', $canManageCourse);
        Context::set('simple_video_learning_enrollment_mode', $this->enrollmentMode($course));
        Context::set('simple_video_learning_enrollment_required', $this->enrollmentMode($course) !== 'open');
        Context::set('simple_video_learning_login_url', $this->loginUrl());
        $this->useFrontendSkin('course');
    }

    public function dispSimple_video_learningLecture(): void
    {
        $documentSrl = (int)Context::get('document_srl');
        $rawLoggedInfo = Context::get('logged_info');
        $loggedInfo = is_object($rawLoggedInfo) ? $rawLoggedInfo : null;
        if (!$loggedInfo || empty($loggedInfo->member_srl)) {
            $this->setRedirectUrl($this->loginUrl());
            return;
        }
        $lecture = LectureModel::getByDocument($documentSrl);
        if (!$lecture || (int)($lecture->course_srl ?? 0) <= 0) {
            throw new \Rhymix\Framework\Exceptions\TargetNotFound();
        }

        $course = CourseModel::get((int)$lecture->course_srl);
        if (!$course) {
            throw new \Rhymix\Framework\Exceptions\TargetNotFound();
        }
        $staffPreview = $this->canManageCourse((int)$lecture->course_srl, $loggedInfo);
        if ((string)($course->is_published ?? 'Y') !== 'Y' && !$staffPreview) {
            throw new \Rhymix\Framework\Exceptions\TargetNotFound();
        }

        if (!$this->canLearn($course, $loggedInfo)) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted('수강신청 후 학습할 수 있습니다.');
        }

        $doc = DocumentModel::getDocument($documentSrl, false, false);
        if (!$doc || !$doc->isExists()) {
            throw new \Rhymix\Framework\Exceptions\TargetNotFound();
        }

        $progress = ProgressModel::get($documentSrl, (int)$loggedInfo->member_srl);

        Context::set('simple_video_learning_course', $course);
        Context::set('simple_video_learning_lecture', $lecture);
        Context::set('simple_video_learning_document', $doc);
        Context::set('simple_video_learning_progress_row', $progress);
        Context::loadFile(['./modules/simple_video_learning/public/tracker.css', 'head']);
        Context::loadFile(['./modules/simple_video_learning/public/tracker.js', 'body']);
        $this->useFrontendSkin('lecture');
    }

    private function canLearn(object $course, ?object $loggedInfo): bool
    {
        if ($this->isPrivileged($loggedInfo)) {
            return true;
        }
        if ($loggedInfo && !empty($loggedInfo->member_srl)
            && CourseRoleModel::isStaff((int)$course->course_srl, (int)$loggedInfo->member_srl)
        ) {
            return true;
        }
        if (!$loggedInfo || empty($loggedInfo->member_srl)) {
            return false;
        }
        if (EnrollmentModel::getStatus((int)$course->course_srl, (int)$loggedInfo->member_srl) === EnrollmentModel::SUSPENDED) {
            return false;
        }
        if ($this->enrollmentMode($course) === 'open') {
            return true;
        }
        return EnrollmentModel::isEnrolled((int)$course->course_srl, (int)$loggedInfo->member_srl);
    }

    private function canManageCourse(int $courseSrl, ?object $loggedInfo): bool
    {
        if ($this->isPrivileged($loggedInfo)) {
            return true;
        }
        return $loggedInfo
            && !empty($loggedInfo->member_srl)
            && CourseRoleModel::isStaff($courseSrl, (int)$loggedInfo->member_srl);
    }

    private function enrollmentMode(object $course): string
    {
        $mode = (string)($course->enrollment_mode ?? '');
        if (in_array($mode, ['open', 'auto', 'approval', 'closed'], true)) {
            return $mode;
        }
        return (string)($course->enrollment_enabled ?? 'Y') === 'N' ? 'open' : 'auto';
    }

    private function loginUrl(): string
    {
        return getNotEncodedUrl(
            '',
            'act',
            'dispMemberLoginForm',
            'success_return_url',
            getRequestUriByServerEnviroment()
        );
    }

    private function isPrivileged(?object $loggedInfo): bool
    {
        return (bool)(
            ($loggedInfo && (($loggedInfo->is_admin ?? 'N') === 'Y')) ||
            !empty($this->grant->manager) ||
            !empty($this->grant->root)
        );
    }
}
