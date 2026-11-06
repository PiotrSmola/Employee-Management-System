{{--
    HR actions of the employee profile (App\View\Components\EmployeeActions, only rendered for
    `manage-employees`). Every form is a modal posting to EmployeeOperationsController; on a
    validation error the profile comes back with the errors in the form's own bag and the
    script at the bottom opens that modal again.
--}}
@php
    $rules = \App\Support\EmploymentRules::class;
    $name = trim($employee->first_name . ' ' . $employee->last_name);
    $modals = [
        'personal' => 'personalModal',
        'transfer' => 'transferModal',
        'title' => 'titleModal',
        'salary' => 'salaryModal',
        'terminate' => 'terminateModal',
        'rehire' => 'rehireModal',
        'correctSalary' => 'correctSalaryModal',
        'correctTitle' => 'correctTitleModal',
        'undo' => 'undoModal',
    ];
    $openModal = collect($modals)->first(fn ($modal, $bag) => $errors->getBag($bag)->any());
    // Old input only for the form that failed (the forms share field names).
    $old = fn (string $bag, string $key, $default = null) => $errors->getBag($bag)->any() ? old($key, $default) : $default;
    $invalid = fn (string $bag, string $key) => $errors->getBag($bag)->has($key) ? 'is-invalid' : '';
    $deptName = fn (string $no) => $departmentNames[$no] ?? $no;
    $possible = fn (?string $min) => $min === null || $min <= $today;
    $hireMax = $minDates['hire'] !== null && $minDates['hire'] < $today ? $minDates['hire'] : $today;
    $undoPresenter = new \App\Support\AuditLogPresenter($departmentNames->all());
@endphp

<div class="row mb-4">
    <div class="col-12">
        <div class="card hr-actions-card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="mb-0 text-body-emphasis">
                    <i class="bi bi-briefcase me-2 text-primary"></i>{{ __('HR actions') }}
                </h5>
                <small class="text-body-secondary">
                    {{ __('History is kept: a change closes the current record and starts a new one on the effective date.') }}
                </small>
            </div>
            <div class="card-body d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#personalModal">
                    <i class="bi bi-pencil-square me-1"></i>{{ __('Edit personal data') }}
                </button>
                @if ($isCurrent)
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#transferModal">
                        <i class="bi bi-arrow-left-right me-1"></i>{{ __('Transfer') }}
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#titleModal">
                        <i class="bi bi-award me-1"></i>{{ __('Change title') }}
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#salaryModal">
                        <i class="bi bi-cash-coin me-1"></i>{{ __('Change salary') }}
                    </button>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#terminateModal">
                        <i class="bi bi-box-arrow-right me-1"></i>{{ __('Terminate employment') }}
                    </button>
                @else
                    <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#rehireModal">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Rehire') }}
                    </button>
                @endif
                @if ($isCurrent && ($latestSalary || $latestTitle))
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-wrench-adjustable me-1"></i>{{ __('Corrections') }}
                        </button>
                        <ul class="dropdown-menu">
                            @if ($latestSalary)
                                <li>
                                    <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#correctSalaryModal">
                                        <i class="bi bi-cash me-2"></i>{{ __('Correct latest salary') }}
                                    </button>
                                </li>
                            @endif
                            @if ($latestTitle)
                                <li>
                                    <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#correctTitleModal">
                                        <i class="bi bi-type me-2"></i>{{ __('Correct latest job title') }}
                                    </button>
                                </li>
                            @endif
                        </ul>
                    </div>
                @endif
                @if ($undoable)
                    <button type="button" class="btn btn-outline-warning ms-md-auto" data-bs-toggle="modal" data-bs-target="#undoModal">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Undo last change') }}
                    </button>
                @endif
            </div>
            @if ($errors->getBag('undo')->any() && ! $undoable)
                <div class="card-footer">
                    @include('employees.partials.hr-errors', ['bag' => 'undo'])
                </div>
            @endif
        </div>
    </div>
</div>

<datalist id="hrTitleOptions">
    @foreach ($titleOptions as $option)
        <option value="{{ $option }}"></option>
    @endforeach
</datalist>

{{-- Edit personal data --}}
<div class="modal fade" id="personalModal" tabindex="-1" aria-labelledby="personalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" action="{{ route('employees.update', $employee->emp_no) }}" class="modal-content" novalidate>
            @csrf
            @method('PUT')
            <input type="hidden" name="snapshot" value="{{ $personalSnapshot }}">
            <div class="modal-header">
                <h5 class="modal-title" id="personalModalLabel"><i class="bi bi-pencil-square me-2"></i>{{ __('Edit personal data') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                @include('employees.partials.hr-errors', ['bag' => 'personal'])
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="hr_first_name" class="form-label fw-semibold">{{ __('First name') }}</label>
                        <input type="text" id="hr_first_name" name="first_name" class="form-control {{ $invalid('personal', 'first_name') }}"
                            value="{{ $old('personal', 'first_name', $employee->first_name) }}" maxlength="{{ $rules::FIRST_NAME_MAX }}" required>
                    </div>
                    <div class="col-sm-6">
                        <label for="hr_last_name" class="form-label fw-semibold">{{ __('Last name') }}</label>
                        <input type="text" id="hr_last_name" name="last_name" class="form-control {{ $invalid('personal', 'last_name') }}"
                            value="{{ $old('personal', 'last_name', $employee->last_name) }}" maxlength="{{ $rules::LAST_NAME_MAX }}" required>
                    </div>
                    <div class="col-sm-4">
                        <label for="hr_gender" class="form-label fw-semibold">{{ __('Gender') }}</label>
                        @php($gender = $old('personal', 'gender', $employee->gender))
                        <select id="hr_gender" name="gender" class="form-select {{ $invalid('personal', 'gender') }}" required>
                            <option value="M" @selected($gender === 'M')>{{ __('Male') }}</option>
                            <option value="F" @selected($gender === 'F')>{{ __('Female') }}</option>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label for="hr_birth_date" class="form-label fw-semibold">{{ __('Birth date') }}</label>
                        <input type="date" id="hr_birth_date" name="birth_date" class="form-control {{ $invalid('personal', 'birth_date') }}"
                            value="{{ $old('personal', 'birth_date', $employee->birth_date) }}" min="{{ $rules::MIN_BIRTH_DATE }}" max="{{ $today }}" required>
                    </div>
                    <div class="col-sm-4">
                        <label for="hr_hire_date" class="form-label fw-semibold">{{ __('Hire date') }}</label>
                        <input type="date" id="hr_hire_date" name="hire_date" class="form-control {{ $invalid('personal', 'hire_date') }}"
                            value="{{ $old('personal', 'hire_date', $employee->hire_date) }}" max="{{ $hireMax }}" required>
                    </div>
                </div>
                <div class="form-text mt-2">
                    @if ($minDates['hire'])
                        {{ __('The hire date cannot be after the first history record (:date).', ['date' => $rules::display($minDates['hire'])]) }}
                    @endif
                    {{ __('Age on the hire date: :min to :max years.', ['min' => $rules::MIN_AGE_AT_HIRE, 'max' => $rules::MAX_AGE_AT_HIRE]) }}
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>{{ __('Save changes') }}</button>
            </div>
        </form>
    </div>
</div>

@if ($isCurrent)
    {{-- Transfer --}}
    <div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('employees.transfer', $employee->emp_no) }}" class="modal-content" novalidate>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="transferModalLabel"><i class="bi bi-arrow-left-right me-2"></i>{{ __('Transfer to another department') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    @include('employees.partials.hr-errors', ['bag' => 'transfer'])
                    <p class="mb-3">
                        {{ __('Current department') }}:
                        <span class="fw-semibold">{{ collect($currentDepartments)->map($deptName)->implode(', ') }}</span>
                    </p>
                    @if ($managedDepartments !== [])
                        <div class="alert alert-warning small" role="alert">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            {{ __(':name is the current manager of :department. The management ends on the transfer date - the department has no current manager until a new one is assigned.', [
                                'name' => $name,
                                'department' => collect($managedDepartments)->map($deptName)->implode(', '),
                            ]) }}
                        </div>
                    @endif
                    <div class="mb-3">
                        <label for="hr_transfer_dept" class="form-label fw-semibold">{{ __('New department') }}</label>
                        @php($selectedDept = $old('transfer', 'dept_no'))
                        <select id="hr_transfer_dept" name="dept_no" class="form-select {{ $invalid('transfer', 'dept_no') }}" required>
                            <option value="">{{ __('Choose a department...') }}</option>
                            @foreach ($departments as $department)
                                @php($held = in_array($department->dept_no, $heldDepartments, true))
                                @php($isCurrentDept = in_array($department->dept_no, $currentDepartments, true))
                                <option value="{{ $department->dept_no }}" @selected($selectedDept === $department->dept_no) @disabled($isCurrentDept)>
                                    {{ $department->dept_name }}
                                    @if ($isCurrentDept)
                                        ({{ __('current') }})
                                    @elseif ($held)
                                        ({{ __('return - worked here before') }})
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ __('A return to a department the employee belonged to before adds a new period; the earlier one stays unchanged.') }}</div>
                    </div>
                    @include('employees.partials.hr-date', ['bag' => 'transfer', 'field' => 'effective_date', 'label' => __('Effective date'), 'min' => $minDates['transfer']])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary" @disabled(! $possible($minDates['transfer']))><i class="bi bi-arrow-left-right me-1"></i>{{ __('Transfer') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Change title --}}
    <div class="modal fade" id="titleModal" tabindex="-1" aria-labelledby="titleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('employees.title', $employee->emp_no) }}" class="modal-content" novalidate>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="titleModalLabel"><i class="bi bi-award me-2"></i>{{ __('Change job title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    @include('employees.partials.hr-errors', ['bag' => 'title'])
                    <p class="mb-3">{{ __('Current job title') }}: <span class="fw-semibold">{{ $currentTitle ?? '-' }}</span></p>
                    <div class="mb-3">
                        <span class="form-label fw-semibold d-block">{{ __('Kind of change') }}</span>
                        @php($kind = $old('title', 'kind', 'promotion'))
                        <div class="btn-group flex-wrap" role="group" aria-label="{{ __('Kind of change') }}">
                            @foreach (['promotion' => __('Promotion'), 'demotion' => __('Demotion'), 'change' => __('Other change')] as $value => $label)
                                <input type="radio" class="btn-check" name="kind" id="hr_kind_{{ $value }}" value="{{ $value }}" autocomplete="off" @checked($kind === $value)>
                                <label class="btn btn-outline-primary" for="hr_kind_{{ $value }}">{{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="hr_title" class="form-label fw-semibold">{{ __('New job title') }}</label>
                        <input type="text" id="hr_title" name="title" list="hrTitleOptions" class="form-control {{ $invalid('title', 'title') }}"
                            value="{{ $old('title', 'title') }}" maxlength="{{ $rules::TITLE_MAX }}" required autocomplete="off">
                    </div>
                    @include('employees.partials.hr-date', ['bag' => 'title', 'field' => 'effective_date', 'label' => __('Effective date'), 'min' => $minDates['title']])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary" @disabled(! $possible($minDates['title']))><i class="bi bi-check-lg me-1"></i>{{ __('Change title') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Change salary --}}
    <div class="modal fade" id="salaryModal" tabindex="-1" aria-labelledby="salaryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('employees.salary', $employee->emp_no) }}" class="modal-content" novalidate
                data-current-salary="{{ $currentSalary ?? '' }}" id="hrSalaryForm">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="salaryModalLabel"><i class="bi bi-cash-coin me-2"></i>{{ __('Change salary') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    @include('employees.partials.hr-errors', ['bag' => 'salary'])
                    <p class="mb-3">
                        {{ __('Current salary') }}:
                        <span class="fw-semibold">{{ $currentSalary !== null ? number_format($currentSalary) . ' USD' : '-' }}</span>
                    </p>
                    @php($mode = $old('salary', 'mode', 'amount'))
                    <div class="btn-group mb-3" role="group" aria-label="{{ __('Enter the change as') }}">
                        <input type="radio" class="btn-check" name="mode" id="hr_mode_amount" value="amount" autocomplete="off" @checked($mode === 'amount')>
                        <label class="btn btn-outline-primary" for="hr_mode_amount">{{ __('New amount') }}</label>
                        <input type="radio" class="btn-check" name="mode" id="hr_mode_percent" value="percent" autocomplete="off" @checked($mode === 'percent')>
                        <label class="btn btn-outline-primary" for="hr_mode_percent">{{ __('Change in %') }}</label>
                    </div>
                    <div class="mb-3" data-salary-mode="amount">
                        <label for="hr_salary_amount" class="form-label fw-semibold">{{ __('New salary (USD per year)') }}</label>
                        <input type="number" id="hr_salary_amount" name="salary" class="form-control {{ $invalid('salary', 'salary') }}"
                            value="{{ $old('salary', 'salary') }}" min="{{ $rules::MIN_SALARY }}" max="{{ $rules::MAX_SALARY }}" step="1">
                    </div>
                    <div class="mb-3" data-salary-mode="percent">
                        <label for="hr_salary_percent" class="form-label fw-semibold">{{ __('Change in percent (negative = cut)') }}</label>
                        <div class="input-group">
                            <input type="number" id="hr_salary_percent" name="percent" class="form-control {{ $invalid('salary', 'percent') }}"
                                value="{{ $old('salary', 'percent') }}" min="{{ $rules::MIN_PERCENT }}" max="{{ $rules::MAX_PERCENT }}" step="0.01">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <p class="small text-body-secondary mb-3" id="hrSalaryPreview" aria-live="polite"></p>
                    <div class="form-check mb-3">
                        <input class="form-check-input {{ $invalid('salary', 'confirm_unusual') }}" type="checkbox" name="confirm_unusual" value="1" id="hr_salary_confirm"
                            @checked($old('salary', 'confirm_unusual'))>
                        <label class="form-check-label small" for="hr_salary_confirm">
                            {{ __('I confirm an unusual change (below :min % or above +:max % of the current salary)', ['min' => $rules::MIN_PERCENT, 'max' => $rules::MAX_PERCENT]) }}
                        </label>
                    </div>
                    @include('employees.partials.hr-date', ['bag' => 'salary', 'field' => 'effective_date', 'label' => __('Effective date'), 'min' => $minDates['salary']])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary" @disabled(! $possible($minDates['salary']))><i class="bi bi-check-lg me-1"></i>{{ __('Change salary') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Terminate --}}
    <div class="modal fade" id="terminateModal" tabindex="-1" aria-labelledby="terminateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('employees.terminate', $employee->emp_no) }}" class="modal-content" novalidate>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title text-danger" id="terminateModalLabel"><i class="bi bi-box-arrow-right me-2"></i>{{ __('Terminate employment') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    @include('employees.partials.hr-errors', ['bag' => 'terminate'])
                    <p>{{ __('On the end date these current records of :name are closed:', ['name' => $name]) }}</p>
                    <ul class="small">
                        <li>{{ __('Department') }}: {{ collect($currentDepartments)->map($deptName)->implode(', ') }}</li>
                        @if ($currentTitle)
                            <li>{{ __('Job title') }}: {{ $currentTitle }}</li>
                        @endif
                        @if ($currentSalary !== null)
                            <li>{{ __('Salary') }}: {{ number_format($currentSalary) }} USD</li>
                        @endif
                        @if ($allManagedDepartments !== [])
                            <li class="text-warning-emphasis">{{ __('Management of') }} {{ collect($allManagedDepartments)->map($deptName)->implode(', ') }}</li>
                        @endif
                    </ul>
                    @include('employees.partials.hr-date', ['bag' => 'terminate', 'field' => 'end_date', 'label' => __('End date'), 'min' => $minDates['terminate']])
                    <div class="mt-3">
                        <label for="hr_reason" class="form-label fw-semibold">{{ __('Reason (optional, kept in the audit log)') }}</label>
                        <textarea id="hr_reason" name="reason" rows="2" maxlength="500" class="form-control {{ $invalid('terminate', 'reason') }}">{{ $old('terminate', 'reason') }}</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-danger" @disabled(! $possible($minDates['terminate']))><i class="bi bi-box-arrow-right me-1"></i>{{ __('Terminate') }}</button>
                </div>
            </form>
        </div>
    </div>
@else
    {{-- Rehire --}}
    <div class="modal fade" id="rehireModal" tabindex="-1" aria-labelledby="rehireModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('employees.rehire', $employee->emp_no) }}" class="modal-content" novalidate>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="rehireModalLabel"><i class="bi bi-arrow-counterclockwise me-2"></i>{{ __('Rehire') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    @include('employees.partials.hr-errors', ['bag' => 'rehire'])
                    @if ($employmentEnd)
                        <p>{{ __('The last employment ended on :date. The new one starts after that date; the original hire date is kept.', ['date' => $rules::display($employmentEnd)]) }}</p>
                    @endif
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="hr_rehire_dept" class="form-label fw-semibold">{{ __('Department') }}</label>
                            @php($selectedDept = $old('rehire', 'dept_no'))
                            <select id="hr_rehire_dept" name="dept_no" class="form-select {{ $invalid('rehire', 'dept_no') }}" required>
                                <option value="">{{ __('Choose a department...') }}</option>
                                @foreach ($departments as $department)
                                    @php($held = in_array($department->dept_no, $heldDepartments, true))
                                    <option value="{{ $department->dept_no }}" @selected($selectedDept === $department->dept_no)>
                                        {{ $department->dept_name }}@if ($held) ({{ __('return - worked here before') }})@endif
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('Any department can be chosen, also one the employee belonged to before.') }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label for="hr_rehire_title" class="form-label fw-semibold">{{ __('Job title') }}</label>
                            <input type="text" id="hr_rehire_title" name="title" list="hrTitleOptions" class="form-control {{ $invalid('rehire', 'title') }}"
                                value="{{ $old('rehire', 'title', $latestTitle?->title) }}" maxlength="{{ $rules::TITLE_MAX }}" required autocomplete="off">
                        </div>
                        <div class="col-sm-6">
                            <label for="hr_rehire_salary" class="form-label fw-semibold">{{ __('Salary (USD per year)') }}</label>
                            <input type="number" id="hr_rehire_salary" name="salary" class="form-control {{ $invalid('rehire', 'salary') }}"
                                value="{{ $old('rehire', 'salary', $latestSalary?->salary) }}" min="{{ $rules::MIN_SALARY }}" max="{{ $rules::MAX_SALARY }}" step="1" required>
                        </div>
                        <div class="col-12">
                            @include('employees.partials.hr-date', ['bag' => 'rehire', 'field' => 'effective_date', 'label' => __('Start date'), 'min' => $minDates['rehire']])
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-success" @disabled(! $possible($minDates['rehire']))><i class="bi bi-check-lg me-1"></i>{{ __('Rehire') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if ($isCurrent && $latestSalary)
    {{-- Correct latest salary --}}
    <div class="modal fade" id="correctSalaryModal" tabindex="-1" aria-labelledby="correctSalaryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route('employees.salary.correct', $employee->emp_no) }}" class="modal-content" novalidate>
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title" id="correctSalaryModalLabel"><i class="bi bi-cash me-2"></i>{{ __('Correct latest salary') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    @include('employees.partials.hr-errors', ['bag' => 'correctSalary'])
                    <p class="small">
                        {{ __('Fixes a typo in the amount of the latest salary record (:from - :to, :amount USD). The dates stay; a real raise or cut belongs to "Change salary". The correction is recorded in the audit log.', [
                            'from' => $rules::display($latestSalary->from_date),
                            'to' => $latestSalary->to_date === $rules::CURRENT ? __('present') : $rules::display($latestSalary->to_date),
                            'amount' => number_format($latestSalary->salary),
                        ]) }}
                    </p>
                    <label for="hr_correct_salary" class="form-label fw-semibold">{{ __('Correct amount (USD per year)') }}</label>
                    <input type="number" id="hr_correct_salary" name="salary" class="form-control {{ $invalid('correctSalary', 'salary') }}"
                        value="{{ $old('correctSalary', 'salary', $latestSalary->salary) }}" min="{{ $rules::MIN_SALARY }}" max="{{ $rules::MAX_SALARY }}" step="1" required>
                    <label for="hr_correct_salary_reason" class="form-label fw-semibold mt-3">{{ __('Reason (required, kept in the audit log)') }}</label>
                    <textarea id="hr_correct_salary_reason" name="reason" rows="2" maxlength="500" required
                        class="form-control {{ $invalid('correctSalary', 'reason') }}">{{ $old('correctSalary', 'reason') }}</textarea>
                    <div class="form-check mt-3">
                        <input class="form-check-input {{ $invalid('correctSalary', 'confirm_unusual') }}" type="checkbox" name="confirm_unusual" value="1" id="hr_correct_confirm"
                            @checked($old('correctSalary', 'confirm_unusual'))>
                        <label class="form-check-label small" for="hr_correct_confirm">
                            {{ __('I confirm an unusual change (below :min % or above +:max % of the recorded amount)', ['min' => $rules::MIN_PERCENT, 'max' => $rules::MAX_PERCENT]) }}
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-wrench-adjustable me-1"></i>{{ __('Save correction') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if ($isCurrent && $latestTitle)
    {{-- Correct latest title --}}
    <div class="modal fade" id="correctTitleModal" tabindex="-1" aria-labelledby="correctTitleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route('employees.title.correct', $employee->emp_no) }}" class="modal-content" novalidate>
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title" id="correctTitleModalLabel"><i class="bi bi-type me-2"></i>{{ __('Correct latest job title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    @include('employees.partials.hr-errors', ['bag' => 'correctTitle'])
                    <p class="small">
                        {{ __('Fixes the wording of the latest job title record (:from - :to, ":title"). The dates stay; a promotion or demotion belongs to "Change title". The correction is recorded in the audit log.', [
                            'from' => $rules::display($latestTitle->from_date),
                            'to' => $latestTitle->to_date === $rules::CURRENT ? __('present') : $rules::display($latestTitle->to_date),
                            'title' => $latestTitle->title,
                        ]) }}
                    </p>
                    <label for="hr_correct_title" class="form-label fw-semibold">{{ __('Correct job title') }}</label>
                    <input type="text" id="hr_correct_title" name="title" list="hrTitleOptions" class="form-control {{ $invalid('correctTitle', 'title') }}"
                        value="{{ $old('correctTitle', 'title', $latestTitle->title) }}" maxlength="{{ $rules::TITLE_MAX }}" required autocomplete="off">
                    <label for="hr_correct_title_reason" class="form-label fw-semibold mt-3">{{ __('Reason (required, kept in the audit log)') }}</label>
                    <textarea id="hr_correct_title_reason" name="reason" rows="2" maxlength="500" required
                        class="form-control {{ $invalid('correctTitle', 'reason') }}">{{ $old('correctTitle', 'reason') }}</textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-wrench-adjustable me-1"></i>{{ __('Save correction') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if ($undoable)
    {{-- Undo the last change --}}
    <div class="modal fade" id="undoModal" tabindex="-1" aria-labelledby="undoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('employees.undo', $employee->emp_no) }}" class="modal-content" novalidate>
                @csrf
                <input type="hidden" name="entry_id" value="{{ $undoable->id }}">
                <div class="modal-header">
                    <h5 class="modal-title" id="undoModalLabel"><i class="bi bi-arrow-counterclockwise me-2"></i>{{ __('Undo last change') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    @include('employees.partials.hr-errors', ['bag' => 'undo'])
                    <p>
                        <span class="badge role-badge {{ $undoPresenter::actionClass($undoable->action) }}">{{ $undoPresenter::actionLabel($undoable->action) }}</span>
                        <span class="small text-body-secondary ms-1">
                            {{ $undoable->created_at->format('d.m.Y H:i') }}, {{ $undoable->user?->name ?? __('System') }}
                        </span>
                    </p>
                    @include('audit-log.partials.changes', ['changes' => $undoable->changes, 'presenter' => $undoPresenter])
                    <p class="small text-body-secondary mt-3 mb-0">
                        {{ __('Undoing restores the records exactly as they were before this change: closed records are reopened and the records it added are removed. Only the most recent change can be undone, within :hours hours and only while nothing else was changed for this employee. The undo is recorded in the audit log.', ['hours' => \App\Services\EmployeeOperationsService::UNDO_HOURS]) }}
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Undo') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const salaryForm = document.getElementById('hrSalaryForm');
        if (salaryForm) {
            const current = parseInt(salaryForm.dataset.currentSalary, 10);
            const amount = salaryForm.querySelector('[name="salary"]');
            const percent = salaryForm.querySelector('[name="percent"]');
            const preview = document.getElementById('hrSalaryPreview');
            const format = (value) => value.toLocaleString('en-US');

            const update = function() {
                const mode = salaryForm.querySelector('[name="mode"]:checked')?.value || 'amount';
                salaryForm.querySelectorAll('[data-salary-mode]').forEach(function(group) {
                    const active = group.dataset.salaryMode === mode;
                    group.classList.toggle('d-none', !active);
                    group.querySelectorAll('input').forEach((input) => input.disabled = !active);
                });

                let next = null;
                if (mode === 'percent' && percent.value !== '' && !isNaN(current)) {
                    next = Math.round(current * (100 + parseFloat(percent.value)) / 100);
                } else if (mode === 'amount' && amount.value !== '') {
                    next = parseInt(amount.value, 10);
                }

                if (next === null || isNaN(next) || isNaN(current)) {
                    preview.textContent = '';
                    return;
                }
                const diff = next - current;
                const pct = current ? (diff / current * 100).toFixed(2) : '0';
                preview.textContent = @json(__('New salary')) + ': ' + format(next) + ' USD (' + (diff >= 0 ? '+' : '') + format(diff) + ', ' + (diff >= 0 ? '+' : '') + pct + ' %)';
            };

            salaryForm.querySelectorAll('[name="mode"]').forEach((radio) => radio.addEventListener('change', update));
            amount.addEventListener('input', update);
            percent.addEventListener('input', update);
            update();
        }

        @if ($openModal)
            const failed = document.getElementById(@json($openModal));
            if (failed && window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(failed).show();
            }
        @endif
    });
</script>
