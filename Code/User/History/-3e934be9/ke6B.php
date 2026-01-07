<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollStat;
use App\Enums\StudentStatus;
use App\Jobs\GenerateAssessmentPdfJob;
use App\Models\Classes;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\GeneralSettingsService;
use Closure;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AdministratorEnrollmentManagementController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService
    ) {}

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

        $enrolledThisSemester = fn () => StudentEnrollment::query()
            ->withTrashed()
            ->where('school_year', $currentSchoolYearString)
            ->where('semester', $currentSemester)
            ->count();

        $enrolledThisSchoolYear = fn () => StudentEnrollment::query()
            ->withTrashed()
            ->where('school_year', $currentSchoolYearString)
            ->count();

        $enrolledPreviousSemester = fn () => StudentEnrollment::query()
            ->withTrashed()
            ->where('school_year', $previousSchoolYearString)
            ->where('semester', $previousSemester)
            ->count();

        $enrolledByDepartment = fn () => StudentEnrollment::query()
            ->withTrashed()
            ->where('student_enrollment.school_year', $currentSchoolYearString)
            ->where('student_enrollment.semester', $currentSemester)
            ->join('courses', DB::raw('CAST(student_enrollment.course_id AS BIGINT)'), '=', 'courses.id')
            ->selectRaw('courses.department as department, count(*) as count')
            ->groupBy('department')
            ->get();

        $enrolledByYearLevel = fn () => StudentEnrollment::query()
            ->withTrashed()
            ->where('school_year', $currentSchoolYearString)
            ->where('semester', $currentSemester)
            ->selectRaw('academic_year as year_level, count(*) as count')
            ->groupBy('academic_year')
            ->get();

        $trashedCount = fn () => StudentEnrollment::query()
            ->onlyTrashed()
            ->where('school_year', $currentSchoolYearString)
            ->where('semester', $currentSemester)
            ->count();

        $activeCount = fn () => StudentEnrollment::query()
            ->where('school_year', $currentSchoolYearString)
            ->where('semester', $currentSemester)
            ->count();

        // Get enrollment status breakdown
        $enrollmentByStatus = fn () => StudentEnrollment::query()
            ->withTrashed()
            ->where('school_year', $currentSchoolYearString)
            ->where('semester', $currentSemester)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->get();

        $applicants = fn () => Student::query()
            ->withTrashed()
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

        $search = request('search');
        $sort = request('sort', 'created_at');
        $direction = request('direction', 'desc');
        $perPage = request('per_page', 10);

        // Normalize per_page
        if ($perPage === 'all') {
            $perPage = 100000;
        } else {
            $perPage = (int) $perPage;
            if ($perPage <= 0) {
                $perPage = 10;
            }
        }

        $enrollments = fn () => StudentEnrollment::query()
            ->withTrashed()
            ->where('student_enrollment.school_year', $currentSchoolYearString)
            ->where('student_enrollment.semester', $currentSemester)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereExists(function ($subquery) use ($search) {
                        $subquery->select(DB::raw(1))
                            ->from('students')
                            ->whereRaw('CAST(NULLIF(student_enrollment.student_id, \'\') AS BIGINT) = students.id')
                            ->where(function ($studentQ) use ($search) {
                                $studentQ->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('middle_name', 'like', "%{$search}%")
                                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                                    ->orWhere('student_id', 'like', "%{$search}%");
                            });
                    })->orWhere(function ($q) use ($search) {
                        $q->whereExists(function ($subquery) use ($search) {
                            $subquery->select(DB::raw(1))
                                ->from('courses')
                                ->whereRaw('CAST(NULLIF(student_enrollment.course_id, \'\') AS BIGINT) = courses.id')
                                ->where('code', 'like', "%{$search}%");
                        });
                    });
                });
            })
            ->with(['student.Course', 'course', 'studentTuition'])
            ->when($sort === 'student_name', function ($query) use ($direction) {
                $query->leftJoin('students', DB::raw('CAST(NULLIF(student_enrollment.student_id, \'\') AS BIGINT)'), '=', 'students.id')
                    ->orderBy('students.last_name', $direction)
                    ->orderBy('students.first_name', $direction)
                    ->select('student_enrollment.*');
            }, function ($query) use ($sort, $direction) {
                // Handle default sorting or specific columns
                if (in_array($sort, ['created_at', 'status', 'school_year', 'semester'])) {
                    $query->orderBy('student_enrollment.'.$sort, $direction);
                } else {
                    $query->orderBy('student_enrollment.created_at', 'desc');
                }
            })
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (StudentEnrollment $enrollment): array => [
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
                'deleted_at' => $enrollment->deleted_at?->toDateTimeString(),
                'is_trashed' => $enrollment->trashed(),
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
            'analytics' => fn () => [
                'current_semester_count' => $enrolledThisSemester instanceof Closure ? $enrolledThisSemester() : $enrolledThisSemester,
                'current_school_year_count' => $enrolledThisSchoolYear instanceof Closure ? $enrolledThisSchoolYear() : $enrolledThisSchoolYear,
                'previous_semester_count' => $enrolledPreviousSemester instanceof Closure ? $enrolledPreviousSemester() : $enrolledPreviousSemester,
                'by_department' => $enrolledByDepartment instanceof Closure ? $enrolledByDepartment() : $enrolledByDepartment,
                'by_year_level' => $enrolledByYearLevel instanceof Closure ? $enrolledByYearLevel() : $enrolledByYearLevel,
                'trashed_count' => $trashedCount instanceof Closure ? $trashedCount() : $trashedCount,
                'active_count' => $activeCount instanceof Closure ? $activeCount() : $activeCount,
                'by_status' => $enrollmentByStatus instanceof Closure ? $enrollmentByStatus() : $enrollmentByStatus,
            ],
            'flash' => session('flash'),
            'filters' => [
                'search' => $search,
                'per_page' => request('per_page', 10), // Pass the raw string/value back
                'currentSemester' => $currentSemester,
                'currentSchoolYear' => $currentSchoolYearStart,
                'systemSemester' => $settingsService->getSystemDefaultSemester(),
                'systemSchoolYear' => $settingsService->getSystemDefaultSchoolYearStart(),
                'availableSemesters' => $settingsService->getAvailableSemesters(),
                'availableSchoolYears' => $settingsService->getAvailableSchoolYears(),
            ],
        ]);
    }

    public function show(StudentEnrollment $enrollment): Response
    {
        $enrollment->load([
            'student.Course',
            'student.studentTuition',
            'subjectsEnrolled.subject',
            'studentTuition',
            'additionalFees',
            'enrollmentTransactions',
            'resources',
        ]);

        // Fetch Class Enrollments (Active)
        $activeClassEnrollments = $enrollment->student->classEnrollments()
            ->with(['class.subject', 'class.faculty', 'class.schedules.room'])
            ->where('status', true)
            ->whereHas('class', function ($query) use ($enrollment) {
                $query->where('school_year', $enrollment->school_year)
                    ->where('semester', $enrollment->semester);
            })
            ->get()
            ->map(fn ($ce) => [
                'id' => $ce->id,
                'class_id' => $ce->class_id,
                'subject_code' => $ce->class->subject_code,
                'subject_title' => $ce->class->subject_title,
                'section' => $ce->class->section,
                'faculty' => $ce->class->faculty->full_name ?? 'TBA',
                'schedule' => $ce->class->schedules->map(fn ($s) => $s->day_of_week.' '.$s->time_range)->implode(', ') ?: 'TBA',
                'room' => $ce->class->schedules->map(fn ($s) => $s->room?->name)->filter()->unique()->implode(', ') ?: 'TBA',
                'grades' => [
                    'prelim' => $ce->prelim_grade,
                    'midterm' => $ce->midterm_grade,
                    'finals' => $ce->finals_grade,
                    'average' => $ce->total_average,
                ],
                'status' => $ce->status,
            ]);

        // Identify Missing Classes
        $enrolledSubjectCodes = $activeClassEnrollments->pluck('subject_code')
            ->map(fn ($code) => array_map('trim', explode(',', (string) $code)))
            ->flatten()
            ->toArray();
        $missingClasses = collect();

        foreach ($enrollment->subjectsEnrolled as $subjectEnrollment) {
            $subject = $subjectEnrollment->subject;
            if (! $subject) {
                continue;
            }

            if (! in_array($subject->code, $enrolledSubjectCodes)) {
                $availableClasses = Classes::query()
                    ->where('school_year', $enrollment->school_year)
                    ->where('semester', $enrollment->semester)
                    ->whereJsonContains('course_codes', $subject->course_id)
                    ->where(function ($query) use ($subject) {
                        $query->whereJsonContains('subject_ids', $subject->id)
                            ->orWhereRaw('LOWER(TRIM(subject_code)) = LOWER(TRIM(?))', [$subject->code])
                            ->orWhereRaw('LOWER(subject_code) LIKE LOWER(?)', ['%'.$subject->code.'%']);
                    })
                    ->withCount('class_enrollments') // Load count
                    ->get()
                    ->map(fn ($class) => [
                        'class_id' => $class->id,
                        'subject_code' => $subject->code,
                        'subject_title' => $class->subject_title,
                        'section' => $class->section,
                        'faculty' => $class->faculty->full_name ?? 'TBA',
                        'available_slots' => ($class->maximum_slots ?: 0) - ($class->class_enrollments_count ?? 0),
                        'max_slots' => $class->maximum_slots ?: 0,
                        'is_full' => ($class->maximum_slots ?: 0) > 0 && ($class->class_enrollments_count ?? 0) >= $class->maximum_slots,
                    ]);

                if ($availableClasses->isEmpty()) {
                    $missingClasses->push([
                        'subject_code' => $subject->code,
                        'subject_title' => $subject->title,
                        'enrollment_status' => 'No Class Offering',
                        'class_id' => null,
                    ]);
                } else {
                    foreach ($availableClasses as $ac) {
                        $missingClasses->push($ac);
                    }
                }
            }
        }

        return Inertia::render('administrators/enrollments/show', [
            'user' => Auth::user(),
            'enrollment' => [
                'id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'status' => $enrollment->status,
                'school_year' => $enrollment->school_year,
                'semester' => $enrollment->semester,
                'academic_year' => $enrollment->academic_year,
                'signature' => $enrollment->signature,
                'student' => [
                    'id' => $enrollment->student->id,
                    'full_name' => $enrollment->student->full_name,
                    'email' => $enrollment->student->email,
                    'student_id' => $enrollment->student->student_id,
                    'course_code' => $enrollment->student->Course?->code,
                ],
                'subjects_enrolled' => $enrollment->subjectsEnrolled->map(fn ($se) => [
                    'id' => $se->id,
                    'subject_code' => $se->subject->code ?? 'Unknown',
                    'subject_title' => $se->subject->title ?? 'Unknown',
                    'units' => $se->subject->units ?? 0,
                    'lecture_fee' => $se->lecture,
                    'lab_fee' => $se->laboratory,
                ]),
                'class_enrollments' => $activeClassEnrollments,
                'missing_classes' => $missingClasses,
                'tuition' => $enrollment->studentTuition ? $enrollment->studentTuition->append('total_paid') : null,
                'additional_fees' => $enrollment->additionalFees,
                'transactions' => $enrollment->enrollmentTransactions,
                'resources' => $enrollment->resources->map(fn ($res) => [
                    'id' => $res->id,
                    'type' => $res->type,
                    'file_name' => $res->file_name,
                    'file_size' => $res->file_size,
                    'created_at' => $res->created_at->toDateTimeString(),
                    'download_url' => route('assessment.download', ['record' => $enrollment->id], false),
                ]),
            ],
            'auth' => [
                'user' => Auth::user(),
                'can_verify_head' => Auth::user()->can('verify_by_head_dept_guest::enrollment') || Auth::user()->hasRole('super_admin'),
                'can_verify_cashier' => Auth::user()->can('verify_by_cashier_guest::enrollment') || Auth::user()->hasRole('super_admin'),
                'is_super_admin' => Auth::user()->hasRole('super_admin'),
            ],
            'flash' => session('flash'),
        ]);
    }

    public function verifyHeadDept(StudentEnrollment $enrollment): RedirectResponse
    {
        if ($this->enrollmentService->verifyByHeadDept($enrollment)) {
            return back()->with('flash', ['success' => 'Successfully verified as Head Dept.']);
        }

        return back()->with('flash', ['error' => 'Verification failed.']);
    }

    public function verifyCashier(Request $request, StudentEnrollment $enrollment): RedirectResponse
    {
        $request->validate([
            'invoicenumber' => 'required|string',
            'settlements' => 'required|array',
            'payment_method' => 'required|string',
        ]);

        // Merge extra dynamic fields for separate transaction fees if present in request
        $allData = $request->all();

        if ($this->enrollmentService->verifyByCashier($enrollment, $allData)) {
            return back()->with('flash', ['success' => 'Successfully enrolled student.']);
        }

        return back()->with('flash', ['error' => 'Enrollment failed.']);
    }

    public function verifyCashierNoReceipt(Request $request, StudentEnrollment $enrollment): RedirectResponse
    {
        if (! Auth::user()->hasRole('super_admin')) {
            abort(403);
        }

        $data = $request->validate([
            'remarks' => 'required|string',
            'confirm_payment' => 'required|accepted',
        ]);

        if ($this->enrollmentService->verifyByCashierWithoutReceipt($enrollment, $data)) {
            return back()->with('flash', ['success' => 'Student enrolled without receipt.']);
        }

        return back()->with('flash', ['error' => 'Enrollment failed.']);
    }

    public function undoCashierVerification(StudentEnrollment $enrollment): RedirectResponse
    {
        if ($this->enrollmentService->undoCashierVerification($enrollment->id)) {
            return back()->with('flash', ['success' => 'Cashier verification undone.']);
        }

        return back()->with('flash', ['error' => 'Undo failed.']);
    }

    public function undoHeadDeptVerification(StudentEnrollment $enrollment): RedirectResponse
    {
        if ($this->enrollmentService->undoHeadDeptVerification($enrollment)) {
            return back()->with('flash', ['success' => 'Head Dept verification undone.']);
        }

        return back()->with('flash', ['error' => 'Undo failed.']);
    }

    public function enrollInClass(Request $request, StudentEnrollment $enrollment): RedirectResponse
    {
        $data = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'force_enrollment' => 'boolean',
        ]);

        try {
            $class = Classes::findOrFail($data['class_id']);
            $student = $enrollment->student;

            // Check if already enrolled
            $exists = \App\Models\ClassEnrollment::where('class_id', $class->id)
                ->where('student_id', $student->id)
                ->exists();

            if ($exists) {
                return back()->with('flash', ['warning' => 'Student is already enrolled in this class.']);
            }

            // Check capacity
            $enrolledCount = \App\Models\ClassEnrollment::where('class_id', $class->id)->count();
            if (! ($data['force_enrollment'] ?? false) && $class->maximum_slots > 0 && $enrolledCount >= $class->maximum_slots) {
                return back()->with('flash', ['error' => 'Class is full. Use force enrollment to override.']);
            }

            \App\Models\ClassEnrollment::create([
                'class_id' => $class->id,
                'student_id' => $student->id,
                'status' => true,
            ]);

            return back()->with('flash', ['success' => "Enrolled in {$class->subject_code}."]);
        } catch (Exception $e) {
            return back()->with('flash', ['error' => $e->getMessage()]);
        }
    }

    public function retryEnrollment(Request $request, StudentEnrollment $enrollment): RedirectResponse
    {
        $force = $request->boolean('force_enrollment', true);
        $originalConfig = config('enrollment.force_enroll_when_full');

        if ($force) {
            config(['enrollment.force_enroll_when_full' => true]);
        }

        try {
            $enrollment->student->autoEnrollInClasses($enrollment->id);

            return back()->with('flash', ['success' => 'Enrollment retry process completed.']);
        } catch (Exception $e) {
            return back()->with('flash', ['error' => $e->getMessage()]);
        } finally {
            if ($force) {
                config(['enrollment.force_enroll_when_full' => $originalConfig]);
            }
        }
    }

    public function resendAssessment(StudentEnrollment $enrollment): RedirectResponse
    {
        $result = $this->enrollmentService->resendAssessmentNotification($enrollment);
        if ($result['success']) {
            return back()->with('flash', ['success' => 'Assessment notification queued.']);
        }

        return back()->with('flash', ['error' => $result['message']]);
    }

    public function createAssessmentPdf(StudentEnrollment $enrollment): RedirectResponse
    {
        try {
            GenerateAssessmentPdfJob::dispatch($enrollment, uniqid('pdf_', true), true);

            return back()->with('flash', ['success' => 'PDF generation queued.']);
        } catch (Exception $e) {
            return back()->with('flash', ['error' => $e->getMessage()]);
        }
    }

    public function quickEnroll(Request $request, StudentEnrollment $enrollment): RedirectResponse
    {
        if (! Auth::user()->hasRole('super_admin')) {
            abort(403);
        }

        $request->validate([
            'remarks' => 'required|string',
            'confirm_emergency' => 'required|accepted',
            'confirm_payment' => 'required|accepted',
        ]);

        try {
            $enrollment->status = EnrollStat::VerifiedByDeptHead->value;
            $enrollment->save();

            $success = $this->enrollmentService->verifyByCashierWithoutReceipt($enrollment, [
                'remarks' => '⚡ QUICK ENROLL: '.$request->input('remarks'),
            ]);

            if ($success) {
                return back()->with('flash', ['success' => 'Quick enrollment successful.']);
            }

            return back()->with('flash', ['error' => 'Quick enrollment failed.']);
        } catch (Exception $e) {
            return back()->with('flash', ['error' => $e->getMessage()]);
        }
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
