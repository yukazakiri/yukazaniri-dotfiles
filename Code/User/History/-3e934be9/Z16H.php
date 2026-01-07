<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollStat;
use App\Enums\StudentStatus;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\GeneralSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AdministratorEnrollmentManagementController extends Controller
{
    public function index(GeneralSettingsService $settingsService): Response|RedirectResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return redirect('/login');
        }

        // Get scoped settings
        $currentSemester = $settingsService->getCurrentSemester();
        $currentSchoolYearStart = $settingsService->getCurrentSchoolYearStart();
        $currentSchoolYearString = $settingsService->getCurrentSchoolYearString();

        // Calculate Analytics
        $previousSemester = $currentSemester === 1 ? 2 : 1;
        $previousSchoolYearStart = $currentSemester === 1 ? $currentSchoolYearStart - 1 : $currentSchoolYearStart;
        $previousSchoolYearString = $previousSchoolYearStart.' - '.($previousSchoolYearStart + 1);

        $enrolledThisSemester = StudentEnrollment::query()
            ->where('school_year', $currentSchoolYearString)
            ->where('semester', $currentSemester)
            ->where('status', EnrollStat::VerifiedByCashier->value)
            ->count();

        $enrolledThisSchoolYear = StudentEnrollment::query()
            ->where('school_year', $currentSchoolYearString)
            ->where('status', EnrollStat::VerifiedByCashier->value)
            ->count();

        $enrolledPreviousSemester = StudentEnrollment::query()
            ->where('school_year', $previousSchoolYearString)
            ->where('semester', $previousSemester)
            ->where('status', EnrollStat::VerifiedByCashier->value)
            ->count();

        $enrolledByDepartment = StudentEnrollment::query()
            ->where('student_enrollment.school_year', $currentSchoolYearString)
            ->where('student_enrollment.semester', $currentSemester)
            ->where('student_enrollment.status', EnrollStat::VerifiedByCashier->value)
            ->join('courses', DB::raw('CAST(student_enrollment.course_id AS BIGINT)'), '=', 'courses.id')
            ->selectRaw('courses.department as department, count(*) as count')
            ->groupBy('department')
            ->get();

        $enrolledByYearLevel = StudentEnrollment::query()
            ->where('school_year', $currentSchoolYearString)
            ->where('semester', $currentSemester)
            ->where('status', EnrollStat::VerifiedByCashier->value)
            ->selectRaw('academic_year as year_level, count(*) as count')
            ->groupBy('academic_year')
            ->get();

        $applicants = Student::query()
            ->where('status', StudentStatus::Applicant)
            ->with('Course')
            ->orderByDesc('created_at')
            ->limit(250)
            ->get()
            ->map(fn (Student $student): array => [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'name' => $student->full_name,
                'student_type' => is_object($student->student_type) ? $student->student_type->value : $student->student_type,
                'course' => $student->Course?->code,
                'department' => $student->Course?->department,
                'academic_year' => $student->academic_year,
                'scholarship_type' => $student->scholarship_type,
                'created_at' => $student->created_at?->toDateTimeString(),
            ]);

        $enrollments = StudentEnrollment::query()
            ->withTrashed()
            ->where('school_year', $currentSchoolYearString)
            ->where('semester', $currentSemester)
            ->where('status', EnrollStat::VerifiedByCashier->value)
            ->with(['student.Course', 'course', 'studentTuition'])
            ->orderByDesc('created_at')
            ->limit(250)
            ->get()
            ->map(fn (StudentEnrollment $enrollment): array => [
                'id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student?->full_name,
                'course' => $enrollment->course?->code,
                'status' => $enrollment->status,
                'school_year' => $enrollment->school_year,
                'semester' => $enrollment->semester,
                'academic_year' => $enrollment->academic_year,
                'subjects_count' => $enrollment->subjectsEnrolled()->count(),
                'tuition' => $enrollment->studentTuition ? [
                    'overall' => $enrollment->studentTuition->overall_tuition,
                    'balance' => $enrollment->studentTuition->total_balance,
                ] : null,
                'created_at' => $enrollment->created_at?->toDateTimeString(),
            ]);

        $scheme = request()->getScheme();
        $adminHost = env('ADMIN_HOST', 'admin.dccp.test');
        $filamentBaseUrl = sprintf('%s://%s/admin/student-enrollments', $scheme, $adminHost);

        return Inertia::render('administrators/enrollments/index', [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar_url ?? null,
                'role' => $user->role?->getLabel() ?? 'Administrator',
            ],
            'filament' => [
                'student_enrollments' => [
                    'index_url' => $filamentBaseUrl,
                    'create_url' => $filamentBaseUrl.'/create',
                ],
            ],
            'applicants' => $applicants,
            'enrollments' => $enrollments,
            'analytics' => [
                'current_semester_count' => $enrolledThisSemester,
                'current_school_year_count' => $enrolledThisSchoolYear,
                'previous_semester_count' => $enrolledPreviousSemester,
                'by_department' => $enrolledByDepartment,
                'by_year_level' => $enrolledByYearLevel,
            ],
            'flash' => session('flash'),
            'filters' => [
                'currentSemester' => $currentSemester,
                'currentSchoolYear' => $currentSchoolYearStart,
                'systemSemester' => $settingsService->getSystemDefaultSemester(),
                'systemSchoolYear' => $settingsService->getSystemDefaultSchoolYearStart(),
                'availableSemesters' => $settingsService->getAvailableSemesters(),
                'availableSchoolYears' => $settingsService->getAvailableSchoolYears(),
            ],
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'scholarship_type' => ['nullable', 'string', 'max:255'],
        ]);

        $student->update([
            'scholarship_type' => $validated['scholarship_type'],
        ]);

        return redirect()->back()->with('flash', [
            'success' => 'Student scholarship status updated successfully.',
        ]);
    }
}
