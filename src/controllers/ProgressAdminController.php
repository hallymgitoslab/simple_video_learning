<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use Context;
use DocumentModel;
use MemberModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\EnrollmentModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\LectureModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\ProgressModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

class ProgressAdminController extends ModuleBase
{
    public function dispSimple_video_learningAdminProgress(): void
    {
        $this->ensureDatabaseReady(true);

        $courses = CourseModel::getAll();
        $courseSrl = (int)Context::get('course_srl');
        if ($courseSrl <= 0 && $courses) {
            $courseSrl = (int)$courses[0]->course_srl;
        }

        $course = $courseSrl > 0 ? CourseModel::get($courseSrl) : null;
        $lectures = $course ? LectureModel::getByCourse($courseSrl) : [];
        foreach ($lectures as $lecture) {
            $doc = DocumentModel::getDocument((int)$lecture->document_srl, false, false);
            $lecture->display_title = ($doc && $doc->isExists())
                ? (string)$doc->getTitleText()
                : (string)$lecture->external_id;
        }

        $enrollments = $course ? EnrollmentModel::getApprovedByCourse($courseSrl) : [];
        $rows = [];
        $selectedMemberSrl = max(0, (int)Context::get('member_srl'));
        $selectedDetails = [];
        $selectedMember = null;

        foreach ($enrollments as $enrollment) {
            $memberSrl = (int)$enrollment->member_srl;
            $member = MemberModel::getMemberInfoByMemberSrl($memberSrl);
            if (!$member || empty($member->member_srl)) {
                continue;
            }

            $watchedSeconds = 0;
            $totalDuration = 0;
            $completedCount = 0;
            $lastHeartbeat = '';
            $details = [];

            foreach ($lectures as $lecture) {
                $duration = max(0, (int)$lecture->duration);
                $totalDuration += $duration;
                $progress = ProgressModel::get((int)$lecture->document_srl, $memberSrl);
                $watched = $progress ? min($duration, max(0, (int)$progress->watched_seconds)) : 0;
                $watchedSeconds += $watched;
                $completed = $progress && (string)$progress->completed === 'Y';
                if ($completed) {
                    $completedCount++;
                }
                if ($progress && (string)$progress->last_heartbeat > $lastHeartbeat) {
                    $lastHeartbeat = (string)$progress->last_heartbeat;
                }

                $details[] = (object)[
                    'lecture' => $lecture,
                    'progress' => $progress,
                    'percentage' => $duration > 0 ? round(($watched / $duration) * 100, 1) : 0,
                    'completed' => $completed,
                ];
            }

            $percentage = $totalDuration > 0
                ? round(($watchedSeconds / $totalDuration) * 100, 1)
                : ($lectures ? round(($completedCount / count($lectures)) * 100, 1) : 0);

            $row = (object)[
                'enrollment' => $enrollment,
                'member' => $member,
                'completed_count' => $completedCount,
                'lecture_count' => count($lectures),
                'percentage' => $percentage,
                'last_heartbeat' => $lastHeartbeat,
            ];
            $rows[] = $row;

            if ($selectedMemberSrl === $memberSrl) {
                $selectedDetails = $details;
                $selectedMember = $member;
            }
        }

        Context::set('simple_video_learning_courses', $courses);
        Context::set('simple_video_learning_selected_course', $course);
        Context::set('simple_video_learning_progress_rows', $rows);
        Context::set('simple_video_learning_selected_member', $selectedMember);
        Context::set('simple_video_learning_selected_details', $selectedDetails);
        $this->setTemplatePath($this->module_path . 'views/admin/');
        $this->setTemplateFile('progress');
    }
}
