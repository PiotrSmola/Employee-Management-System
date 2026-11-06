@include('layouts.html')
@include('layouts.head', ['pageTitle' => $department->dept_name . ' - ' . __('Departments')])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('departments.index') }}">{{ __('Departments') }}</a></li>
                <li class="breadcrumb-item active">{{ $department->dept_name }}</li>
            </ol>
        </nav>

        @include('layouts.flash')

        <div class="card gradient-card-1 text-white mb-4">
            <div class="card-body">
                <div class="row align-items-center g-3">
                    <div class="col-md-7">
                        <div class="d-flex align-items-center">
                            <div class="bg-white bg-opacity-25 rounded-circle d-flex align-items-center justify-content-center me-4"
                                style="width: 80px; height: 80px;">
                                <i class="bi bi-diagram-3 display-5 text-white"></i>
                            </div>
                            <div>
                                <h1 class="mb-1">{{ $department->dept_name }}</h1>
                                <p class="mb-1 opacity-90"><i class="bi bi-hash me-1"></i>{{ __('Code') }}: {{ $department->dept_no }}</p>
                                <p class="mb-0 opacity-90">
                                    <i class="bi bi-person-badge me-1"></i>{{ __('Manager') }}:
                                    @if ($currentManager)
                                        @if ($currentManager->hidden)
                                            <span class="fst-italic">{{ __('outside your department') }}</span>
                                        @else
                                            <a href="{{ route('employees.show', $currentManager->emp_no) }}" class="text-white fw-semibold">{{ $currentManager->name }}</a>
                                        @endif
                                        ({{ __('since :date', ['date' => $currentManager->from]) }})
                                    @else
                                        <span class="fst-italic">{{ __('none') }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5 text-md-end">
                        <a href="{{ route('employees.index', ['department' => $department->dept_no]) }}" class="btn btn-light mb-2">
                            <i class="bi bi-people me-1"></i>{{ __('Employees in the directory') }}
                        </a>
                        @can('update', $department)
                            <a href="{{ route('departments.edit', $department) }}" class="btn btn-outline-light mb-2">
                                <i class="bi bi-pencil me-1"></i>{{ __('Rename') }}
                            </a>
                        @endcan
                        @can('delete', $department)
                            <form method="POST" action="{{ route('departments.destroy', $department) }}" class="d-inline"
                                onsubmit="return confirm(@js(__('Delete the department :name?', ['name' => $department->dept_name])))">
                                @csrf
                                @method('DELETE')
                                <span class="d-inline-block" @if ($deletionBlocked) tabindex="0" title="{{ $deletionBlocked }}" @endif>
                                    <button type="submit" class="btn btn-outline-light mb-2" @disabled($deletionBlocked)>
                                        <i class="bi bi-trash me-1"></i>{{ __('Delete') }}
                                    </button>
                                </span>
                            </form>
                        @endcan
                    </div>
                </div>
            </div>
        </div>

        <x-figures-status :status="$figuresStatus" />

        @if ($figuresReady)
        {{-- Key figures --}}
        <div class="row g-3 mb-4 justify-content-center">
            <div class="col-md-6 col-xl-3">
                <div class="card gradient-card-2 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-people display-5 mb-2 opacity-75"></i>
                        <h3 class="fw-bold">{{ number_format($headcount) }}</h3>
                        <p class="mb-0 opacity-90">{{ __('Current employees') }}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card gradient-card-3 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-gender-ambiguous display-5 mb-2 opacity-75"></i>
                        <h3 class="fw-bold">{{ $femalePercent }}% / {{ $malePercent }}%</h3>
                        <p class="mb-0 opacity-90">
                            {{ __('Women / men') }}
                            <small>({{ number_format($female) }} / {{ number_format($male) }})</small>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card gradient-card-4 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-hourglass-split display-5 mb-2 opacity-75"></i>
                        <h3 class="fw-bold">{{ $avgTenureYears !== null ? number_format($avgTenureYears, 1) : '-' }}</h3>
                        <p class="mb-0 opacity-90">{{ __('Avg. years with the company') }}</p>
                    </div>
                </div>
            </div>
            @if ($canSeeSalaries && $salary)
                <div class="col-md-6 col-xl-3">
                    <div class="card gradient-card-1 text-white h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-currency-dollar display-5 mb-2 opacity-75"></i>
                            <h3 class="fw-bold">{{ $salary['avg'] !== null ? '$' . number_format($salary['avg']) : '-' }}</h3>
                            <p class="mb-0 opacity-90">
                                {{ __('Avg. salary') }}
                                @if ($salary['min'] !== null)
                                    <small class="d-block">{{ __('min') }} ${{ number_format($salary['min']) }} &middot; {{ __('max') }} ${{ number_format($salary['max']) }}</small>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-bar-chart me-2 text-primary"></i>{{ __('Headcount by year') }}</h5>
                        @if ($lastDataYear)
                            <p class="mb-0 text-muted small">{{ __('Assignments in the department at the end of each year, up to the last year with data (:year)', ['year' => $lastDataYear]) }}</p>
                        @endif
                    </div>
                    <div class="card-body">
                        @if ($headcountByYear->isNotEmpty())
                            <div style="position: relative; height: 300px;">
                                <canvas id="headcountChart" data-rows="{{ $headcountByYear->toJson() }}"
                                    data-label="{{ __('Employees') }}" data-year-label="{{ __('Year') }}"></canvas>
                            </div>
                        @else
                            <p class="text-muted text-center my-5">{{ __('No employees yet') }}</p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-briefcase me-2 text-primary"></i>{{ __('Job titles') }}</h5>
                    </div>
                    <div class="card-body">
                        @forelse ($titles as $title)
                            <div class="d-flex justify-content-between align-items-center mb-3 {{ !$loop->last ? 'border-bottom pb-3' : '' }}">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold">{{ $title->title }}</div>
                                    <div class="progress mt-1" style="height: 5px;">
                                        <div class="progress-bar" style="width: {{ $title->share }}%"></div>
                                    </div>
                                </div>
                                <span class="badge bg-info text-dark ms-3">{{ number_format($title->total) }}</span>
                            </div>
                        @empty
                            <p class="text-muted text-center">{{ __('No job title data found') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        @endif

        <div class="row g-4 mb-4">
            <div class="{{ $canManage ? 'col-lg-7' : 'col-12' }}">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-person-badge me-2 text-primary"></i>{{ __('Managers') }}</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-primary">
                                    <tr>
                                        <th scope="col">{{ __('Manager') }}</th>
                                        <th scope="col">{{ __('Period') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($managerHistory as $manager)
                                        <tr class="{{ $manager->isCurrent ? 'table-info' : '' }}">
                                            <td>
                                                @if ($manager->hidden)
                                                    <span class="fst-italic text-muted">{{ __('A manager outside your department') }}</span>
                                                @else
                                                    <a href="{{ route('employees.show', $manager->emp_no) }}" class="fw-semibold">{{ $manager->name }}</a>
                                                    <small class="text-muted d-block">#{{ $manager->emp_no }}</small>
                                                @endif
                                            </td>
                                            <td>
                                                {{ $manager->from }} &ndash; {{ $manager->to }}
                                                @if ($manager->isCurrent)
                                                    <small class="text-success fw-semibold d-block"><i class="bi bi-check-circle me-1"></i>{{ __('Current manager') }}</small>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="2" class="text-center py-4 text-muted">{{ __('The department has never had a manager.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            @can('manage-departments')
                <div class="col-lg-5">
                    @include('departments.partials.manage-manager')
                </div>
            @endcan
        </div>

        @if ($figuresReady)
        <div class="row g-4">
            @foreach ($recentLists as $list)
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-{{ $list['icon'] }} me-2 text-primary"></i>{{ $list['title'] }}</h5>
                        </div>
                        <div class="card-body">
                            @forelse ($list['rows'] as $row)
                                <div class="d-flex align-items-center {{ !$loop->last ? 'border-bottom pb-2 mb-2' : '' }}">
                                    <div class="flex-grow-1">
                                        @if ($row->hidden)
                                            <span class="fst-italic text-muted">{{ __('Employee moved to another department') }}</span>
                                        @else
                                            <a href="{{ route('employees.show', $row->emp_no) }}" class="fw-semibold">{{ $row->name }}</a>
                                        @endif
                                        <small class="text-muted d-block">
                                            <i class="bi bi-calendar3 me-1"></i>{{ $row->dateLabel }}
                                        </small>
                                    </div>
                                    @if ($row->badge)
                                        <span class="badge {{ $row->badgeClass }} role-badge">{{ $row->badge }}</span>
                                    @endif
                                </div>
                            @empty
                                <p class="text-muted text-center mb-0">{{ __('Nobody yet') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @endif
    </main>

    @include('layouts.footer', ['fixedBottom' => false])

    @if ($headcountByYear->isNotEmpty())
        @include('layouts.chartjs')
        <script src="{{ asset('js/department-headcount-chart.js') }}"></script>
    @endif
</body>

</html>
