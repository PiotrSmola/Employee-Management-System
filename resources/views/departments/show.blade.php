@php
    $date = fn (?string $value) => $value === null || $value === '9999-01-01' ? __('Present') : \Carbon\Carbon::parse($value)->format('d.m.Y');
    $person = fn (object $row) => $row->first_name . ' ' . $row->last_name;
    $male = $gender['M'];
    $female = $gender['F'];
    $people = max(1, $male + $female);
@endphp
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
                                        @if (isset($linkable[$currentManager->emp_no]))
                                            <a href="{{ route('employees.show', $currentManager->emp_no) }}" class="text-white fw-semibold">{{ $person($currentManager) }}</a>
                                        @else
                                            <span class="fw-semibold">{{ $person($currentManager) }}</span>
                                        @endif
                                        ({{ __('since :date', ['date' => $date($currentManager->from_date)]) }})
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
                        <h3 class="fw-bold">{{ round($female / $people * 100) }}% / {{ round($male / $people * 100) }}%</h3>
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
                        <p class="mb-0 text-muted small">{{ __('Employees in the department at the end of each year') }}</p>
                    </div>
                    <div class="card-body">
                        @if ($headcountByYear->isNotEmpty())
                            <div style="position: relative; height: 300px;">
                                <canvas id="headcountChart"></canvas>
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
                                        <div class="progress-bar" style="width: {{ $headcount > 0 ? ($title->total / $headcount) * 100 : 0 }}%"></div>
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

        <div class="row g-4 mb-4">
            <div class="{{ auth()->user()->can('manage-departments') ? 'col-lg-7' : 'col-12' }}">
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
                                        @php($isCurrent = $manager->to_date === '9999-01-01')
                                        <tr class="{{ $isCurrent ? 'table-info' : '' }}">
                                            <td>
                                                @if (isset($linkable[$manager->emp_no]))
                                                    <a href="{{ route('employees.show', $manager->emp_no) }}" class="fw-semibold">{{ $person($manager) }}</a>
                                                @else
                                                    <span class="fw-semibold">{{ $person($manager) }}</span>
                                                @endif
                                                <small class="text-muted d-block">#{{ $manager->emp_no }}</small>
                                            </td>
                                            <td>
                                                {{ $date($manager->from_date) }} &ndash; {{ $date($manager->to_date) }}
                                                @if ($isCurrent)
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

        <div class="row g-4">
            @foreach ([
                ['title' => __('Recently joined'), 'icon' => 'box-arrow-in-right', 'rows' => $recentJoiners, 'joined' => true],
                ['title' => __('Recently left'), 'icon' => 'box-arrow-right', 'rows' => $recentLeavers, 'joined' => false],
            ] as $list)
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-{{ $list['icon'] }} me-2 text-primary"></i>{{ $list['title'] }}</h5>
                        </div>
                        <div class="card-body">
                            @forelse ($list['rows'] as $row)
                                <div class="d-flex align-items-center {{ !$loop->last ? 'border-bottom pb-2 mb-2' : '' }}">
                                    <div class="flex-grow-1">
                                        @if (isset($linkable[$row->emp_no]))
                                            <a href="{{ route('employees.show', $row->emp_no) }}" class="fw-semibold">{{ $person($row) }}</a>
                                        @else
                                            <span class="fw-semibold">{{ $person($row) }}</span>
                                        @endif
                                        <small class="text-muted d-block">
                                            <i class="bi bi-calendar3 me-1"></i>{{ $date($row->date) }}
                                        </small>
                                    </div>
                                    @if ($list['joined'])
                                        <span class="badge {{ $row->hired ? 'text-bg-success' : 'text-bg-primary' }} role-badge">
                                            {{ $row->hired ? __('Hired') : __('Transferred in') }}
                                        </span>
                                    @elseif ($row->moved_to)
                                        <span class="badge text-bg-primary role-badge">{{ __('Moved to :name', ['name' => $row->moved_to]) }}</span>
                                    @else
                                        <span class="badge text-bg-secondary role-badge">{{ __('Left the company') }}</span>
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
    </main>

    @include('layouts.footer', ['fixedBottom' => false])

    @if ($headcountByYear->isNotEmpty())
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const rows = @json($headcountByYear);

                new Chart(document.getElementById('headcountChart'), {
                    type: 'bar',
                    data: {
                        labels: rows.map(row => row.year.toString()),
                        datasets: [{
                            label: @json(__('Employees')),
                            data: rows.map(row => row.headcount),
                            backgroundColor: 'rgba(102, 126, 234, 0.7)',
                            borderColor: '#667eea',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { callback: value => value.toLocaleString() }
                            },
                            x: {
                                title: { display: true, text: @json(__('Year')) }
                            }
                        }
                    }
                });
            });
        </script>
    @endif
</body>

</html>
