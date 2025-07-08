@include('layouts.html')
@include('layouts.head', [
    'pageTitle' => 'Employee Details - ' . $employee->first_name . ' ' . $employee->last_name,
])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employee List</a></li>
                <li class="breadcrumb-item active">{{ $employee->first_name }} {{ $employee->last_name }}</li>
            </ol>
        </nav>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card gradient-card-1 text-white">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center">
                                    <div class="bg-white bg-opacity-25 rounded-circle d-flex align-items-center justify-content-center me-4"
                                        style="width: 80px; height: 80px;">
                                        <i class="bi bi-person display-4 text-white"></i>
                                    </div>
                                    <div>
                                        <h1 class="mb-1">{{ $employee->first_name }} {{ $employee->last_name }}</h1>
                                        <p class="mb-1 opacity-90">
                                            <i class="bi bi-hash me-1"></i>
                                            Employee ID: {{ $employee->emp_no }}
                                        </p>
                                        <p class="mb-1 opacity-90">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            Hired:
                                            {{ \Carbon\Carbon::parse($employee->hire_date)->format('d.m.Y') }}
                                            @if (!$stats['isCurrentEmployee'] && $stats['employmentEndDate'])
                                                - {{ $stats['employmentEndDate']->format('d.m.Y') }}
                                            @elseif($stats['isCurrentEmployee'])
                                                - Present
                                            @endif
                                        </p>
                                        <p class="mb-0 opacity-90">
                                            <i
                                                class="bi bi-gender-{{ $employee->gender === 'M' ? 'male' : 'female' }} me-1"></i>
                                            {{ $employee->gender === 'M' ? 'Male' : 'Female' }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end">
                                @if ($employee->currentDepartment->count() > 0)
                                    <h4 class="mb-2">
                                        <span class="badge bg-white text-primary">
                                            {{ $employee->currentDepartment->first()->dept_name }}
                                        </span>
                                    </h4>
                                @else
                                    <h4 class="mb-2">
                                        <span class="badge bg-secondary">Former Employee</span>
                                    </h4>
                                @endif

                                @if ($employee->currentTitle)
                                    <p class="mb-1 opacity-90">{{ $employee->currentTitle->title }}</p>
                                @endif

                                @if ($employee->currentSalary)
                                    <h5 class="mb-0">
                                        <i class="bi bi-currency-dollar me-1"></i>
                                        {{ number_format($employee->currentSalary->salary) }} USD
                                    </h5>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card gradient-card-2 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-currency-dollar display-4 mb-2 opacity-75"></i>
                        <h4>${{ number_format($stats['totalEarnings']) }}</h4>
                        <p class="mb-0 opacity-90">Total Earnings</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card gradient-card-3 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-graph-up display-4 mb-2 opacity-75"></i>
                        <h4>${{ number_format($stats['avgSalary']) }}</h4>
                        <p class="mb-0 opacity-90">Average Salary</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card gradient-card-4 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-calendar-check display-4 mb-2 opacity-75"></i>
                        @if ($stats['totalDays'] > 0)
                            @if ($stats['yearsEmployed'] > 0)
                                <h4>
                                    {{ (int) $stats['yearsEmployed'] }}
                                    {{ (int) $stats['yearsEmployed'] == 1 ? 'year' : ((int) $stats['yearsEmployed'] < 5 ? 'years' : 'years') }}
                                    @if ($stats['remainingDays'] > 0)
                                        & {{ $stats['remainingDays'] }}
                                        {{ $stats['remainingDays'] == 1 ? 'day' : 'days' }}
                                    @endif
                                </h4>
                                <p class="mb-0 opacity-90">
                                    <small>({{ $stats['totalDays'] }} days total)</small>
                                </p>
                            @else
                                <h4>{{ $stats['totalDays'] }} {{ $stats['totalDays'] == 1 ? 'day' : 'days' }}</h4>
                                <p class="mb-0 opacity-90">of employment</p>
                            @endif
                        @else
                            <h4>0 days</h4>
                            <p class="mb-0 opacity-90">of employment</p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card gradient-card-1 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-arrow-repeat display-4 mb-2 opacity-75"></i>
                        <h4>{{ $stats['departmentChanges'] }}</h4>
                        <p class="mb-0 opacity-90">Department Changes</p>
                    </div>
                </div>
            </div>
        </div>

        @if ($salaryChartData->count() > 0)
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-graph-up me-2 text-primary"></i>
                                Salary History (Average Annual Salary)
                            </h5>
                        </div>
                        <div class="card-body">
                            <canvas id="salaryChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history me-2 text-primary"></i>
                            Employment History
                        </h5>
                        <p class="mb-0 text-muted small">Table showing salary changes with corresponding job titles
                            and departments</p>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-primary">
                                    <tr>
                                        <th scope="col" style="width: 20%;">Period</th>
                                        <th scope="col" style="width: 25%;">Salary</th>
                                        <th scope="col" style="width: 27.5%;">Job Title</th>
                                        <th scope="col" style="width: 27.5%;">Department</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($combinedHistory as $history)
                                        <tr class="{{ $history->is_current ? 'table-info' : '' }}">
                                            <td>
                                                <div class="fw-semibold">
                                                    {{ \Carbon\Carbon::parse($history->from_date)->format('d.m.Y') }}
                                                </div>
                                                <small class="text-muted">
                                                    @if ($history->to_date !== '9999-01-01')
                                                        to
                                                        {{ \Carbon\Carbon::parse($history->to_date)->format('d.m.Y') }}
                                                    @else
                                                        to Present
                                                    @endif
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-salary fs-6">
                                                    ${{ number_format($history->salary) }}
                                                </span>
                                                @if ($history->is_current)
                                                    <br><small class="text-success fw-semibold">Current Salary</small>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="fw-medium">{{ $history->title }}</div>
                                                @if ($history->is_current)
                                                    <small class="text-success">
                                                        <i class="bi bi-check-circle me-1"></i>Current Position
                                                    </small>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-department">
                                                    {{ $history->department_name }}
                                                </span>
                                                @if ($history->is_current)
                                                    <br><small class="text-success fw-semibold">Current
                                                        Department</small>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
                                                <i class="bi bi-info-circle display-4 d-block mb-2"></i>
                                                <p class="mb-0">No employment history available</p>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12 text-center">
                <a href="{{ route('employees.index') }}" class="btn btn-outline-primary me-3">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Employee List
                </a>
                <a href="{{ route('employees.export-show-pdf', $employee->emp_no) }}" class="btn btn-danger">
                    <i class="bi bi-file-earmark-pdf me-1"></i>
                    Export Details to PDF
                </a>
            </div>
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])

    @if ($salaryChartData->count() > 0)
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const ctx = document.getElementById('salaryChart').getContext('2d');
                const salaryData = @json($salaryChartData);

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: salaryData.map(item => item.year.toString()),
                        datasets: [{
                            label: 'Średnia pensja (USD)',
                            data: salaryData.map(item => item.salary),
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#3b82f6',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'Średnia pensja: $' + context.parsed.y.toLocaleString();
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                ticks: {
                                    callback: function(value) {
                                        return '$' + value.toLocaleString();
                                    }
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Rok'
                                }
                            }
                        },
                        elements: {
                            point: {
                                hoverRadius: 8
                            }
                        }
                    }
                });
            });
        </script>
    @endif

</body>

</html>
