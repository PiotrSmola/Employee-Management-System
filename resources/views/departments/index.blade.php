@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Departments') . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">{{ __('Departments') }}</li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h3 mb-1">
                    <i class="bi bi-diagram-3 me-2 text-primary"></i>
                    {{ __('Departments') }}
                </h1>
                <p class="text-muted mb-0">
                    @if ($isDepartmentScoped)
                        {{ __('You see the department you manage.') }}
                    @else
                        {{ __('Current headcount, managers and job titles of every department.') }}
                    @endif
                </p>
            </div>
            @can('create', App\Models\Department::class)
                <a href="{{ route('departments.create') }}" class="btn btn-success">
                    <i class="bi bi-plus-lg me-1"></i>{{ __('New department') }}
                </a>
            @endcan
        </div>

        @include('layouts.flash')
        @include('departments.partials.figures-status')

        <div class="row g-4">
            @forelse ($departments as $department)
                <div class="col-md-6 col-xl-4">
                    <div class="card shadow-sm h-100 department-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 text-truncate">
                                <a href="{{ route('departments.show', $department->dept_no) }}"
                                    class="text-decoration-none text-body-emphasis stretched-link">
                                    {{ $department->dept_name }}
                                </a>
                            </h5>
                            <span class="badge bg-department ms-2">{{ $department->dept_no }}</span>
                        </div>
                        <div class="card-body">
                            <div class="row text-center g-2 mb-3">
                                <div class="col">
                                    <div class="fs-4 fw-bold text-primary">{{ $department->headcount !== null ? number_format($department->headcount) : '-' }}</div>
                                    <small class="text-muted">{{ __('Employees') }}</small>
                                </div>
                                <div class="col">
                                    <div class="fs-4 fw-bold text-primary">{{ $department->titles !== null ? number_format($department->titles) : '-' }}</div>
                                    <small class="text-muted">{{ __('Job titles') }}</small>
                                </div>
                                @if ($canSeeSalaries)
                                    <div class="col">
                                        <div class="fs-4 fw-bold text-success">
                                            {{ $department->avg_salary !== null ? '$' . number_format($department->avg_salary) : '-' }}
                                        </div>
                                        <small class="text-muted">{{ __('Avg. salary') }}</small>
                                    </div>
                                @endif
                            </div>
                            <div class="d-flex align-items-center border-top pt-3">
                                <i class="bi bi-person-badge fs-4 text-primary me-2"></i>
                                <div class="small">
                                    <div class="text-muted">{{ __('Manager') }}</div>
                                    @if ($department->manager?->hidden)
                                        <span class="text-muted fst-italic">{{ __('outside your department') }}</span>
                                    @elseif ($department->manager)
                                        {{-- Above the card's stretched link. --}}
                                        <a href="{{ route('employees.show', $department->manager->emp_no) }}"
                                            class="fw-semibold position-relative" style="z-index: 2;">
                                            {{ $department->manager->first_name }} {{ $department->manager->last_name }}
                                        </a>
                                        <span class="text-muted">
                                            {{ __('since :date', ['date' => \Carbon\Carbon::parse($department->manager->from_date)->format('d.m.Y')]) }}
                                        </span>
                                    @else
                                        <span class="text-muted fst-italic">{{ __('No current manager') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5 text-muted">
                            <i class="bi bi-diagram-3 display-4 d-block mb-2"></i>
                            @if ($isDepartmentScoped)
                                {{ __('No department is assigned to your account.') }}
                            @else
                                {{ __('No departments found') }}
                            @endif
                        </div>
                    </div>
                </div>
            @endforelse
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
