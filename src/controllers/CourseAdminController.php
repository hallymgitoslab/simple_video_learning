<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use Context;
use DocumentModel;
use ModuleModel;
use MemberModel;
use Rhymix\Framework\Session;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseRoleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\LectureModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

class CourseAdminController extends ModuleBase
{
    public function dispSimple_video_learningAdminCourses(): void
    {
        $this->ensureDatabaseReady(true);
        $courses = CourseModel::getAll();
        $lectures = LectureModel::getAll();

        foreach ($lectures as $lecture) {
            $documentSrl = (int)$lecture->document_srl;
            $doc = $documentSrl > 0 ? DocumentModel::getDocument($documentSrl, false, false) : null;
            $lecture->display_title = ($doc && $doc->isExists())
                ? (string)$doc->getTitleText()
                : (string)$lecture->external_id;
        }

        $instances = ModuleModel::getMidList((object)['module' => 'simple_video_learning']);
        $frontMid = '';
        if ($instances) {
            $first = reset($instances);
            $frontMid = $first && !empty($first->mid) ? (string)$first->mid : '';
        }

        $rolesByCourse = [];
        foreach ($courses as $course) {
            $roles = [];
            foreach (CourseRoleModel::getByCourse((int)$course->course_srl) as $roleRow) {
                $member = MemberModel::getMemberInfoByMemberSrl((int)$roleRow->member_srl);
                if ($member && !empty($member->member_srl)) {
                    $roleRow->member = $member;
                    $roles[] = $roleRow;
                }
            }
            $rolesByCourse[(int)$course->course_srl] = $roles;
        }

        Context::set('simple_video_learning_courses', $courses);
        Context::set('simple_video_learning_lectures', $lectures);
        Context::set('simple_video_learning_roles_by_course', $rolesByCourse);
        Context::set('simple_video_learning_front_mid', $frontMid);
        Context::set('simple_video_learning_csrf_token', Session::getGenericToken());
        $this->setTemplatePath($this->module_path . 'views/admin/');
        $this->setTemplateFile('courses');
    }

    public function dispSimple_video_learningAdminCourseCreate(): void
    {
        $this->dispSimple_video_learningAdminCourses();
        $this->setTemplateFile('course_create');
    }

    public function dispSimple_video_learningAdminCourseDetail(): void
    {
        $this->dispSimple_video_learningAdminCourses();
        \Context::set('simple_video_learning_selected_course_srl', (int)\Context::get('course_srl'));
        $this->setTemplateFile('course_detail');
    }

    public function dispSimple_video_learningAdminLectureAssignments(): void
    {
        $this->dispSimple_video_learningAdminCourses();
        $this->setTemplateFile('lecture_assignments');
    }

    public function procSimple_video_learningAdminSaveCourse()
    {
        $this->ensureDatabaseReady(true);
        $vars = Context::getRequestVars();
        $courseSrl = (int)($vars->course_srl ?? 0);
        $courseCode = trim((string)($vars->course_code ?? ''));
        $title = trim((string)($vars->title ?? ''));
        $numSections = max(1, min(99, (int)($vars->num_sections ?? 15)));

        if ($courseCode === '' || $title === '') {
            return new \BaseObject(-1, '수업 코드와 수업명은 필수입니다.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $courseCode)) {
            return new \BaseObject(-1, '수업 코드는 영문, 숫자, 점(.), 밑줄(_), 하이픈(-)만 사용할 수 있습니다.');
        }

        $sameCode = CourseModel::getByCode($courseCode);
        if ($sameCode && (int)$sameCode->course_srl !== $courseSrl) {
            return new \BaseObject(-1, '이미 사용 중인 수업 코드입니다.');
        }

        if ($courseSrl > 0) {
            foreach (LectureModel::getByCourse($courseSrl) as $lecture) {
                if ((int)($lecture->week_no ?? 1) > $numSections) {
                    return new \BaseObject(-1, '현재 ' . (int)$lecture->week_no . '주차에 강의가 있어 섹션 수를 줄일 수 없습니다.');
                }
            }
        }

        $output = CourseModel::save([
            'course_srl' => $courseSrl,
            'course_code' => $courseCode,
            'title' => $title,
            'description' => trim((string)($vars->description ?? '')),
            'num_sections' => $numSections,
            'enrollment_mode' => in_array((string)($vars->enrollment_mode ?? 'auto'), ['open', 'auto', 'approval', 'closed'], true)
                ? (string)$vars->enrollment_mode
                : 'auto',
            'is_published' => (string)($vars->is_published ?? 'Y') === 'N' ? 'N' : 'Y',
        ]);
        if (!$output->toBool()) {
            return $output;
        }

        $this->setMessage('success_saved');
        $this->setRedirectUrl(Context::get('success_return_url'));
    }

    public function procSimple_video_learningAdminDeleteCourse()
    {
        $courseSrl = (int)Context::get('course_srl');
        if ($courseSrl <= 0 || !CourseModel::get($courseSrl)) {
            return new \BaseObject(-1, '수업을 찾을 수 없습니다.');
        }
        if (LectureModel::getByCourse($courseSrl)) {
            return new \BaseObject(-1, '강의가 연결된 수업은 삭제할 수 없습니다. 먼저 강의 연결을 해제하세요.');
        }

        $output = CourseModel::delete($courseSrl);
        if (!$output->toBool()) {
            return $output;
        }
        $this->setMessage('success_deleted');
        $this->setRedirectUrl(Context::get('success_return_url'));
    }


    public function procSimple_video_learningAdminAssignCourseRole()
    {
        $this->ensureDatabaseReady(true);

        $courseSrl = (int)Context::get('course_srl');
        $userId = trim((string)Context::get('user_id'));
        $role = trim((string)Context::get('role'));
        if (!CourseModel::get($courseSrl)) {
            return new \BaseObject(-1, '수업을 찾을 수 없습니다.');
        }
        $member = MemberModel::getMemberInfoByUserID($userId);
        if (!$member || empty($member->member_srl)) {
            return new \BaseObject(-1, '회원 ID를 찾을 수 없습니다.');
        }

        $loggedInfo = Context::get('logged_info');
        $output = CourseRoleModel::assign(
            $courseSrl,
            (int)$member->member_srl,
            $role,
            (int)($loggedInfo->member_srl ?? 0)
        );
        if (!$output->toBool()) {
            return $output;
        }

        $this->setMessage('수업 운영 권한을 저장했습니다.');
        $this->setRedirectUrl(Context::get('success_return_url'));
    }

    public function procSimple_video_learningAdminRemoveCourseRole()
    {
        $this->ensureDatabaseReady(true);

        $courseSrl = (int)Context::get('course_srl');
        $memberSrl = (int)Context::get('member_srl');
        $loggedInfo = Context::get('logged_info');
        $output = CourseRoleModel::remove(
            $courseSrl,
            $memberSrl,
            (int)($loggedInfo->member_srl ?? 0)
        );
        if (!$output->toBool()) {
            return $output;
        }

        $this->setMessage('수업 운영 권한을 해제했습니다.');
        $this->setRedirectUrl(Context::get('success_return_url'));
    }

    public function procSimple_video_learningAdminAssignLecture()
    {
        $documentSrl = (int)Context::get('document_srl');
        $courseSrl = max(0, (int)Context::get('course_srl'));
        $sectionNo = max(1, (int)Context::get('week_no'));
        $sortOrder = max(0, (int)Context::get('sort_order'));

        if ($courseSrl > 0) {
            $course = CourseModel::get($courseSrl);
            if (!$course) {
                return new \BaseObject(-1, '수업을 찾을 수 없습니다.');
            }
            $numSections = max(1, (int)($course->num_sections ?? 15));
            if ($sectionNo > $numSections) {
                return new \BaseObject(-1, '존재하지 않는 섹션입니다.');
            }
        }

        $output = LectureModel::assignCourse($documentSrl, $courseSrl, $sectionNo, $sortOrder);
        if (!$output->toBool()) {
            return $output;
        }

        $this->setMessage('success_saved');
        $this->setRedirectUrl(Context::get('success_return_url'));
    }
}
