@include('layouts.html')
@include('layouts.head', ['pageTitle' => 'Employee Management - Dashboard'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="text-center">
                    <h1 class="display-4 fw-bold text-primary mb-3">
                        <i class="bi bi-building me-3"></i>
                        Employee Management System
                    </h1>
                    <p class="lead text-muted">Comprehensive employee data management and analytics platform</p>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-3 justify-content-center">
            <div class="col-md-3">
                <div class="card gradient-card-1 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-people display-4 mb-3 opacity-75"></i>
                        <h3 class="card-title fw-bold">{{ number_format($totalEmployees) }}</h3>
                        <p class="card-text opacity-90">Total Employees</p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card gradient-card-2 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-diagram-3 display-4 mb-3 opacity-75"></i>
                        <h3 class="card-title fw-bold">{{ $totalDepartments }}</h3>
                        <p class="card-text opacity-90">Departments</p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card gradient-card-3 text-white h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-currency-dollar display-4 mb-3 opacity-75"></i>
                        <h3 class="card-title fw-bold">${{ number_format($averageSalary, 0) }}</h3>
                        <p class="card-text opacity-90">Average Salary</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="row mb-3 mt-4">
            <div class="col-12 text-center">
                <a href="{{ route('employees.index') }}" class="btn btn-primary btn-lg me-3 mb-3">
                    <i class="bi bi-list-ul me-2"></i>
                    Browse All Employees
                </a>
            </div>
        </div>

        <!-- Recent Data -->
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-clock-history me-2 text-primary"></i>
                            Recently Hired
                        </h5>
                    </div>
                    <div class="card-body">
                        @forelse($recentEmployees as $employee)
                            <div class="d-flex align-items-center mb-3 {{ !$loop->last ? 'border-bottom pb-3' : '' }}">
                                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-3"
                                    style="width: 40px; height: 40px;">
                                    <i class="bi bi-person text-white"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold">{{ $employee->first_name }} {{ $employee->last_name }}
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        Hired: {{ \Carbon\Carbon::parse($employee->hire_date)->format('M d, Y') }}
                                    </small>
                                </div>
                                <span class="badge bg-success">{{ $employee->emp_no }}</span>
                            </div>
                        @empty
                            <p class="text-muted text-center">No recent employees found</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-pie-chart me-2 text-primary"></i>
                            Employees by Department
                        </h5>
                    </div>
                    <div class="card-body">
                        @forelse($employeesByDepartment as $dept)
                            <div
                                class="d-flex justify-content-between align-items-center mb-3 {{ !$loop->last ? 'border-bottom pb-3' : '' }}">
                                <div>
                                    <div class="fw-semibold">{{ $dept->dept_name }}</div>
                                    <div class="progress mt-1" style="height: 5px;">
                                        <div class="progress-bar bg-primary"
                                            style="width: {{ ($dept->total / $totalEmployees) * 100 }}%"></div>
                                    </div>
                                </div>
                                <span class="badge bg-info text-dark ms-3">{{ number_format($dept->total) }}</span>
                            </div>
                        @empty
                            <p class="text-muted text-center">No department data found</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
