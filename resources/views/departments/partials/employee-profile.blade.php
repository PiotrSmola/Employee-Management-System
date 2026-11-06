{{--
    Employee profile: manager of the employee's current department and the employee's own
    department management periods. Included by employees/show.blade.php (needs $employee).
--}}
@inject('departmentStatistics', 'App\Services\DepartmentStatistics')
@php
    $departmentRoles = $departmentStatistics->forEmployee($employee);
    $visibility = App\Support\EmployeeVisibility::current();
    $managesNow = $departmentRoles['managedPeriods']->contains('to_date', '9999-01-01');
    // Managers can only open their own department's page.
    $canOpen = fn (string $deptNo) => ! $visibility->isDepartmentScoped() || $visibility->department() === $deptNo;
    $periodDate = fn (string $value) => $value === '9999-01-01' ? __('Present') : \Carbon\Carbon::parse($value)->format('d.m.Y');
@endphp
@if ($departmentRoles['departmentManagers']->isNotEmpty() || $departmentRoles['managedPeriods']->isNotEmpty())
    <div class="card mb-4" id="department-management">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            @foreach ($departmentRoles['departmentManagers'] as $row)
                <div class="d-flex align-items-center me-lg-4">
                    <i class="bi bi-person-badge fs-3 text-primary me-2"></i>
                    <div>
                        <div class="small text-muted">
                            {{ __('Department manager') }}
                            @if ($canOpen($row->dept_no))
                                (<a href="{{ route('departments.show', $row->dept_no) }}">{{ $row->dept_name }}</a>)
                            @else
                                ({{ $row->dept_name }})
                            @endif
                        </div>
                        @if ($row->manager === null)
                            <span class="fst-italic text-muted">{{ __('none') }}</span>
                        @elseif ((int) $row->manager->emp_no === (int) $employee->emp_no)
                            <span class="fw-semibold">{{ __('this employee') }}</span>
                        @elseif ($visibility->canView($row->manager->emp_no))
                            <a href="{{ route('employees.show', $row->manager->emp_no) }}" class="fw-semibold">
                                {{ $row->manager->first_name }} {{ $row->manager->last_name }}
                            </a>
                        @else
                            <span class="text-muted">&ndash;</span>
                        @endif
                    </div>
                </div>
            @endforeach

            @if ($departmentRoles['managedPeriods']->isNotEmpty())
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <span class="badge role-badge {{ $managesNow ? 'text-bg-warning' : 'text-bg-secondary' }}">
                        <i class="bi bi-award me-1"></i>
                        {{ $managesNow ? __('Department manager') : __('Former department manager') }}
                    </span>
                    @foreach ($departmentRoles['managedPeriods'] as $period)
                        <small class="text-muted">
                            @if ($canOpen($period->dept_no))
                                <a href="{{ route('departments.show', $period->dept_no) }}">{{ $period->dept_name }}</a>:
                            @else
                                {{ $period->dept_name }}:
                            @endif
                            {{ $periodDate($period->from_date) }} &ndash; {{ $periodDate($period->to_date) }}{{ $loop->last ? '' : ';' }}
                        </small>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif
