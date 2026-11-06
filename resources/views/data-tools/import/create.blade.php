@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Import employees') . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">{{ __('Employees') }}</a></li>
                <li class="breadcrumb-item active">{{ __('Import employees') }}</li>
            </ol>
        </nav>

        <h1 class="h3 mb-4">
            <i class="bi bi-upload me-2 text-primary"></i>
            {{ __('Import employees') }}
        </h1>

        @include('layouts.flash')

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-file-earmark-arrow-up me-2"></i>{{ __('Upload a file') }}</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('employees.import.upload') }}" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label for="file" class="form-label fw-semibold">{{ __('CSV or Excel (XLSX) file') }}</label>
                                <input type="file" id="file" name="file" accept=".csv,.xlsx,.txt"
                                    class="form-control @error('file') is-invalid @enderror" required>
                                @error('file')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-text">
                                    {{ __('Up to :rows employees and :size per file. Nothing is saved before you confirm the preview.', [
                                        'rows' => number_format($maxRows),
                                        'size' => $maxFileSize,
                                    ]) }}
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search me-1"></i>{{ __('Check file') }}
                            </button>
                        </form>

                        <hr>

                        <h6 class="text-body-emphasis">{{ __('Template') }}</h6>
                        <p class="small text-body-secondary mb-2">{{ __('A header row and one example employee:') }}</p>
                        <a href="{{ route('employees.import.template', 'csv') }}" class="btn btn-sm btn-outline-success me-1">
                            <i class="bi bi-filetype-csv me-1"></i>{{ __('CSV template') }}
                        </a>
                        <a href="{{ route('employees.import.template', 'xlsx') }}" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-file-earmark-excel me-1"></i>{{ __('Excel template') }}
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-list-check me-2"></i>{{ __('File format') }}</h5>
                    </div>
                    <div class="card-body">
                        <p class="small">
                            {{ __('One row per new employee, with a header row naming the columns (any order). Every imported employee starts with a current department, job title and salary from the hire date. Employee numbers are assigned automatically.') }}
                        </p>
                        <table class="table table-sm small mb-3">
                            <thead>
                                <tr>
                                    <th>{{ __('Column') }}</th>
                                    <th>{{ __('Rule') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td><code>first_name</code></td><td>{{ __('required, at most 14 characters') }}</td></tr>
                                <tr><td><code>last_name</code></td><td>{{ __('required, at most 16 characters') }}</td></tr>
                                <tr><td><code>birth_date</code></td><td>{{ __('date YYYY-MM-DD, at least 14 years before the hire date') }}</td></tr>
                                <tr><td><code>gender</code></td><td><code>M</code> {{ __('or') }} <code>F</code></td></tr>
                                <tr><td><code>hire_date</code></td><td>{{ __('date YYYY-MM-DD, at most one year in the future') }}</td></tr>
                                <tr><td><code>department</code></td><td>{{ __('an existing department code (below)') }}</td></tr>
                                <tr><td><code>title</code></td><td>{{ __('an existing job title (below)') }}</td></tr>
                                <tr><td><code>salary</code></td><td>{{ __('whole number greater than 0') }}</td></tr>
                            </tbody>
                        </table>
                        <p class="small text-body-secondary">
                            {{ __('A row with the same first name, last name and birth date as another row or an existing employee is rejected as a duplicate. The file is imported only when every row is valid.') }}
                        </p>
                        <div class="row small">
                            <div class="col-sm-7">
                                <div class="fw-semibold mb-1">{{ __('Department codes') }}</div>
                                <ul class="list-unstyled mb-2">
                                    @forelse ($departments as $department)
                                        <li><code>{{ $department->dept_no }}</code> {{ $department->dept_name }}</li>
                                    @empty
                                        <li class="text-body-secondary">{{ __('No departments yet.') }}</li>
                                    @endforelse
                                </ul>
                            </div>
                            <div class="col-sm-5">
                                <div class="fw-semibold mb-1">{{ __('Job titles') }}</div>
                                <ul class="list-unstyled mb-0">
                                    @forelse ($titles as $title)
                                        <li>{{ $title }}</li>
                                    @empty
                                        <li class="text-body-secondary">{{ __('No job titles yet.') }}</li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
