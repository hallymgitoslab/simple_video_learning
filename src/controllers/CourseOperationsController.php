<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use Context;
use DocumentController;
use DocumentModel;
use MemberModel;
use Rhymix\Framework\Session;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseApiTokenModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseRoleModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\EnrollmentModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\LectureModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\ProgressModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

class CourseOperationsController extends ModuleBase
{
    public function dispSimple_video_learningManageCourse(): void
    {
        $loggedInfo = $this->requireMember();
        $this->ensureDatabaseReady(($loggedInfo->is_admin ?? 'N') === 'Y');

        $courseSrl = (int)Context::get('course_srl');
        $course = CourseModel::get($courseSrl);
        if (!$course) {
            throw new \Rhymix\Framework\Exceptions\TargetNotFound();
        }
        $permissions = $this->permissions($courseSrl, $loggedInfo);
        if (!$permissions['manage_enrollments'] && !$permissions['edit_activities']) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }

        $enrollments = EnrollmentModel::getByCourse($courseSrl);

        $lectures = LectureModel::getByCourse($courseSrl);
        foreach ($lectures as $lecture) {
            $doc = DocumentModel::getDocument((int)$lecture->document_srl, false, false);
            $lecture->display_title = ($doc && $doc->isExists())
                ? (string)$doc->getTitleText()
                : (string)$lecture->external_id;
        }

        foreach ($enrollments as $row) {
            $row->member = MemberModel::getMemberInfoByMemberSrl((int)$row->member_srl);
            $row->progress_percentage = 0;
            $row->completed_count = 0;
            $row->lecture_count = count($lectures);
            $row->last_heartbeat = '';

            if ((string)($row->status ?? '') === EnrollmentModel::APPROVED) {
                $watched = 0;
                $duration = 0;
                foreach ($lectures as $lecture) {
                    $lectureDuration = max(0, (int)$lecture->duration);
                    $duration += $lectureDuration;
                    $progress = ProgressModel::get((int)$lecture->document_srl, (int)$row->member_srl);
                    if (!$progress) {
                        continue;
                    }
                    $watched += min($lectureDuration, max(0, (int)$progress->watched_seconds));
                    if ((string)$progress->completed === 'Y') {
                        $row->completed_count++;
                    }
                    if ((string)$progress->last_heartbeat > $row->last_heartbeat) {
                        $row->last_heartbeat = (string)$progress->last_heartbeat;
                    }
                }
                $row->progress_percentage = $duration > 0 ? round(($watched / $duration) * 100, 1) : 0;
            }
        }

        $roles = CourseRoleModel::getByCourse($courseSrl);
        foreach ($roles as $row) {
            $row->member = MemberModel::getMemberInfoByMemberSrl((int)$row->member_srl);
        }

        Context::set('simple_video_learning_course', $course);
        Context::set('simple_video_learning_enrollments', $enrollments);
        Context::set('simple_video_learning_course_roles', $roles);
        Context::set('simple_video_learning_manage_permissions', (object)$permissions);
        Context::set('simple_video_learning_manage_lectures', $lectures);
        Context::set('simple_video_learning_course_api_tokens', $permissions['edit_activities'] ? CourseApiTokenModel::getByCourse($courseSrl) : []);
        Context::set('simple_video_learning_new_api_token', Session::get('simple_video_learning.new_api_token.' . $courseSrl));
        Session::set('simple_video_learning.new_api_token.' . $courseSrl, null);
        Context::set('simple_video_learning_csrf_token', Session::getGenericToken());
        $this->useFrontendSkin('manage');
    }


    public function dispSimple_video_learningCourseAttendance(): void
    {
        $loggedInfo = $this->requireMember();
        $this->ensureDatabaseReady(($loggedInfo->is_admin ?? 'N') === 'Y');

        $courseSrl = (int)Context::get('course_srl');
        $course = CourseModel::get($courseSrl);
        if (!$course) {
            throw new \Rhymix\Framework\Exceptions\TargetNotFound();
        }

        $permissions = $this->permissions($courseSrl, $loggedInfo);
        if (!$permissions['view_progress']) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }

        $lectures = LectureModel::getByCourse($courseSrl);
        foreach ($lectures as $lecture) {
            $doc = DocumentModel::getDocument((int)$lecture->document_srl, false, false);
            $lecture->display_title = ($doc && $doc->isExists())
                ? (string)$doc->getTitleText()
                : (string)$lecture->external_id;
        }

        $enrollments = EnrollmentModel::getApprovedByCourse($courseSrl);
        $rows = [];
        $selectedMemberSrl = max(0, (int)Context::get('member_srl'));
        $selectedMember = null;
        $selectedDetails = [];

        foreach ($enrollments as $enrollment) {
            $memberSrl = (int)$enrollment->member_srl;
            $member = MemberModel::getMemberInfoByMemberSrl($memberSrl);
            if (!$member || empty($member->member_srl)) {
                continue;
            }

            $totalDuration = 0;
            $watchedSeconds = 0;
            $completedCount = 0;
            $startedCount = 0;
            $lastHeartbeat = '';
            $details = [];

            foreach ($lectures as $lecture) {
                $duration = max(0, (int)$lecture->duration);
                $progress = ProgressModel::get((int)$lecture->document_srl, $memberSrl);
                $watched = $progress ? min($duration, max(0, (int)$progress->watched_seconds)) : 0;
                $percentage = $duration > 0 ? round(($watched / $duration) * 100, 1) : 0;
                $completed = $progress && (string)$progress->completed === 'Y';

                $totalDuration += $duration;
                $watchedSeconds += $watched;
                if ($progress) {
                    $startedCount++;
                }
                if ($completed) {
                    $completedCount++;
                }
                if ($progress && (string)$progress->last_heartbeat > $lastHeartbeat) {
                    $lastHeartbeat = (string)$progress->last_heartbeat;
                }

                $details[] = (object)[
                    'lecture' => $lecture,
                    'progress' => $progress,
                    'watched_seconds' => $watched,
                    'duration' => $duration,
                    'percentage' => $percentage,
                    'completed' => $completed,
                    'status' => !$progress ? 'not_started' : ($completed ? 'completed' : 'in_progress'),
                ];
            }

            $overall = $totalDuration > 0
                ? round(($watchedSeconds / $totalDuration) * 100, 1)
                : ($lectures ? round(($completedCount / count($lectures)) * 100, 1) : 0);

            $row = (object)[
                'enrollment' => $enrollment,
                'member' => $member,
                'lecture_count' => count($lectures),
                'started_count' => $startedCount,
                'completed_count' => $completedCount,
                'watched_seconds' => $watchedSeconds,
                'total_duration' => $totalDuration,
                'percentage' => $overall,
                'last_heartbeat' => $lastHeartbeat,
            ];
            $rows[] = $row;

            if ($selectedMemberSrl === $memberSrl) {
                $selectedMember = $member;
                $selectedDetails = $details;
            }
        }

        Context::set('simple_video_learning_course', $course);
        Context::set('simple_video_learning_attendance_rows', $rows);
        Context::set('simple_video_learning_attendance_lectures', $lectures);
        Context::set('simple_video_learning_selected_member', $selectedMember);
        Context::set('simple_video_learning_selected_details', $selectedDetails);
        Context::set('simple_video_learning_manage_permissions', (object)$permissions);
        $this->useFrontendSkin('attendance');
    }

    public function procSimple_video_learningEnrollmentDecision()
    {
        $loggedInfo = $this->requireMember();
        $courseSrl = (int)Context::get('course_srl');
        $memberSrl = (int)Context::get('member_srl');
        $decision = (string)Context::get('decision');

        $permissions = $this->permissions($courseSrl, $loggedInfo);
        if (!$permissions['manage_enrollments']) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }

        if ($decision === 'approve') {
            $output = EnrollmentModel::approve($courseSrl, $memberSrl, (int)$loggedInfo->member_srl);
        } elseif ($decision === 'reject') {
            $output = EnrollmentModel::reject($courseSrl, $memberSrl, (int)$loggedInfo->member_srl);
        } elseif ($decision === 'suspend') {
            $output = EnrollmentModel::suspend($courseSrl, $memberSrl, (int)$loggedInfo->member_srl);
        } else {
            return new \BaseObject(-1, '지원하지 않는 수강 처리입니다.');
        }

        if (!$output->toBool()) {
            return $output;
        }
        $this->setMessage('수강 상태를 변경했습니다.');
        $this->setRedirectUrl($this->returnUrl($courseSrl));
    }

    public function procSimple_video_learningManualEnroll()
    {
        $loggedInfo = $this->requireMember();
        $courseSrl = (int)Context::get('course_srl');
        $permissions = $this->permissions($courseSrl, $loggedInfo);
        if (!$permissions['manage_enrollments']) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }

        $userId = trim((string)Context::get('user_id'));
        $member = MemberModel::getMemberInfoByUserID($userId);
        if (!$member || empty($member->member_srl)) {
            return new \BaseObject(-1, '회원 ID를 찾을 수 없습니다.');
        }

        $output = EnrollmentModel::approve($courseSrl, (int)$member->member_srl, (int)$loggedInfo->member_srl);
        if (!$output->toBool()) {
            return $output;
        }

        $this->setMessage('수강생을 등록했습니다.');
        $this->setRedirectUrl($this->returnUrl($courseSrl));
    }

    public function procSimple_video_learningAssignCourseRole()
    {
        $loggedInfo = $this->requireMember();
        $courseSrl = (int)Context::get('course_srl');
        $role = (string)Context::get('role');
        $permissions = $this->permissions($courseSrl, $loggedInfo);

        $sitePrivileged = $permissions['site_privileged'];
        if (!$sitePrivileged && !$permissions['edit_activities']) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }
        if (!$sitePrivileged && $role !== CourseRoleModel::ASSISTANT) {
            return new \BaseObject(-1, '교수는 조교만 추가할 수 있습니다.');
        }

        $userId = trim((string)Context::get('user_id'));
        $member = MemberModel::getMemberInfoByUserID($userId);
        if (!$member || empty($member->member_srl)) {
            return new \BaseObject(-1, '회원 ID를 찾을 수 없습니다.');
        }

        if (!$sitePrivileged
            && CourseRoleModel::getRole($courseSrl, (int)$member->member_srl) === CourseRoleModel::TEACHER
        ) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted('교수 역할은 관리자만 변경할 수 있습니다.');
        }

        $output = CourseRoleModel::assign(
            $courseSrl,
            (int)$member->member_srl,
            $role,
            (int)$loggedInfo->member_srl
        );
        if (!$output->toBool()) {
            return $output;
        }
        $this->setMessage('수업 운영 권한을 저장했습니다.');
        $this->setRedirectUrl($this->returnUrl($courseSrl));
    }

    public function procSimple_video_learningRemoveCourseRole()
    {
        $loggedInfo = $this->requireMember();
        $courseSrl = (int)Context::get('course_srl');
        $targetMemberSrl = (int)Context::get('member_srl');
        $targetRole = CourseRoleModel::getRole($courseSrl, $targetMemberSrl);
        $permissions = $this->permissions($courseSrl, $loggedInfo);

        if (!$permissions['site_privileged']) {
            if (!$permissions['edit_activities'] || $targetRole !== CourseRoleModel::ASSISTANT) {
                throw new \Rhymix\Framework\Exceptions\NotPermitted();
            }
        }

        $output = CourseRoleModel::remove($courseSrl, $targetMemberSrl, (int)$loggedInfo->member_srl);
        if (!$output->toBool()) {
            return $output;
        }
        $this->setMessage('수업 운영 권한을 해제했습니다.');
        $this->setRedirectUrl($this->returnUrl($courseSrl));
    }


    public function procSimple_video_learningCreateCourseApiToken()
    {
        $loggedInfo = $this->requireMember();
        $courseSrl = (int)Context::get('course_srl');
        $permissions = $this->permissions($courseSrl, $loggedInfo);
        if (!$permissions['edit_activities']) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }

        [$output, $plain] = CourseApiTokenModel::generate(
            $courseSrl,
            (int)$loggedInfo->member_srl,
            trim((string)Context::get('label'))
        );
        if (!$output->toBool()) {
            return $output;
        }

        Session::set('simple_video_learning.new_api_token.' . $courseSrl, $plain);
        $this->setMessage('API 토큰을 생성했습니다. 원문은 이번 한 번만 표시됩니다.');
        $this->setRedirectUrl($this->returnUrl($courseSrl));
    }

    public function procSimple_video_learningRevokeCourseApiToken()
    {
        $loggedInfo = $this->requireMember();
        $courseSrl = (int)Context::get('course_srl');
        $permissions = $this->permissions($courseSrl, $loggedInfo);
        if (!$permissions['edit_activities']) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }

        $tokenSrl = (int)Context::get('token_srl');
        $allowed = false;
        foreach (CourseApiTokenModel::getByCourse($courseSrl) as $token) {
            if ((int)$token->token_srl === $tokenSrl) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }

        $output = CourseApiTokenModel::revoke($tokenSrl);
        if (!$output->toBool()) {
            return $output;
        }
        $this->setMessage('API 토큰을 폐기했습니다.');
        $this->setRedirectUrl($this->returnUrl($courseSrl));
    }

    public function procSimple_video_learningDeleteLecture()
    {
        $loggedInfo = $this->requireMember();
        $documentSrl = (int)Context::get('document_srl');
        $lecture = LectureModel::getByDocument($documentSrl);
        if (!$lecture) {
            return new \BaseObject(-1, '강의를 찾을 수 없습니다.');
        }

        $courseSrl = (int)$lecture->course_srl;
        $permissions = $this->permissions($courseSrl, $loggedInfo);
        if (!$permissions['edit_activities']) {
            throw new \Rhymix\Framework\Exceptions\NotPermitted();
        }

        $doc = DocumentModel::getDocument($documentSrl, false, false);
        if ($doc && $doc->isExists()) {
            $output = DocumentController::getInstance()->deleteDocument($documentSrl, true);
            if (!$output->toBool()) {
                return $output;
            }
        } else {
            executeQuery('simple_video_learning.deleteProgressByDocument', ['document_srl' => $documentSrl]);
            executeQuery('simple_video_learning.deleteLectureByDocument', ['document_srl' => $documentSrl]);
        }

        $this->setMessage('강의와 연결된 영상/진도 기록을 삭제했습니다.');
        $this->setRedirectUrl($this->returnUrl($courseSrl));
    }

    private function requireMember(): object
    {
        $loggedInfo = Context::get('logged_info');
        if (!$loggedInfo || empty($loggedInfo->member_srl)) {
            throw new \Rhymix\Framework\Exceptions\MustLogin();
        }
        return $loggedInfo;
    }

    private function permissions(int $courseSrl, object $loggedInfo): array
    {
        $sitePrivileged = (($loggedInfo->is_admin ?? 'N') === 'Y')
            || !empty($this->grant->manager)
            || !empty($this->grant->root);

        $memberSrl = (int)$loggedInfo->member_srl;
        return [
            'site_privileged' => $sitePrivileged,
            'edit_activities' => $sitePrivileged || CourseRoleModel::canEditActivities($courseSrl, $memberSrl),
            'manage_enrollments' => $sitePrivileged || CourseRoleModel::canManageEnrollments($courseSrl, $memberSrl),
            'view_progress' => $sitePrivileged || CourseRoleModel::canViewProgress($courseSrl, $memberSrl),
        ];
    }

    private function returnUrl(int $courseSrl): string
    {
        $return = (string)Context::get('success_return_url');
        return $return !== ''
            ? $return
            : getNotEncodedUrl('', 'mid', (string)$this->mid, 'act', 'dispSimple_video_learningManageCourse', 'course_srl', $courseSrl);
    }
}
