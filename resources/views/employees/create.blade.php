@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Add employee') . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employee List</a></li>
                <li class="breadcrumb-item active">{{ __('Add employee') }}</li>
            </ol>
        </nav>

        <h1 class="h3 mb-4">
            <i class="bi bi-person-plus me-2 text-primary"></i>{{ __('Add employee') }}
        </h1>

        @include('layouts.flash')

        <form method="POST" action="{{ route('employees.store') }}" novalidate>
            @csrf
            <input type="hidden" name="form_token" value="{{ old('form_token', $formToken) }}">
            @error('confirm_duplicate')
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-people me-1"></i>{{ $message }}
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="confirm_duplicate" value="1" id="confirm_duplicate">
                        <label class="form-check-label fw-semibold" for="confirm_duplicate">{{ __('This is a different person - hire anyway') }}</label>
                    </div>
                </div>
            @enderror
            @error('form_token')
                <div class="alert alert-danger" role="alert">{{ __('The form has expired. Reload the page and try again.') }}</div>
            @enderror
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0 text-body-emphasis"><i class="bi bi-person-vcard me-2"></i>{{ __('Personal data') }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="first_name" class="form-label fw-semibold">{{ __('First name') }}</label>
                                    <input type="text" id="first_name" name="first_name" value="{{ old('first_name') }}"
                                        class="form-control @error('first_name') is-invalid @enderror" maxlength="{{ $rules::FIRST_NAME_MAX }}" required autofocus>
                                    @error('first_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-sm-6">
                                    <label for="last_name" class="form-label fw-semibold">{{ __('Last name') }}</label>
                                    <input type="text" id="last_name" name="last_name" value="{{ old('last_name') }}"
                                        class="form-control @error('last_name') is-invalid @enderror" maxlength="{{ $rules::LAST_NAME_MAX }}" required>
                                    @error('last_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-sm-6">
                                    <label for="gender" class="form-label fw-semibold">{{ __('Gender') }}</label>
                                    <select id="gender" name="gender" class="form-select @error('gender') is-invalid @enderror" required>
                                        <option value="">{{ __('Choose...') }}</option>
                                        <option value="M" @selected(old('gender') === 'M')>{{ __('Male') }}</option>
                                        <option value="F" @selected(old('gender') === 'F')>{{ __('Female') }}</option>
                                    </select>
                                    @error('gender')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-sm-6">
                                    <label for="birth_date" class="form-label fw-semibold">{{ __('Birth date') }}</label>
                                    <input type="date" id="birth_date" name="birth_date" value="{{ old('birth_date') }}"
                                        min="{{ $rules::MIN_BIRTH_DATE }}" max="{{ $today }}"
                                        class="form-control @error('birth_date') is-invalid @enderror" required>
                                    @error('birth_date')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text">{{ __('Age on the hire date: :min to :max years.', ['min' => $rules::MIN_AGE_AT_HIRE, 'max' => $rules::MAX_AGE_AT_HIRE]) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0 text-body-emphasis"><i class="bi bi-briefcase me-2"></i>{{ __('Employment') }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="hire_date" class="form-label fw-semibold">{{ __('Hire date') }}</label>
                                    <input type="date" id="hire_date" name="hire_date" value="{{ old('hire_date', $today) }}" max="{{ $today }}"
                                        class="form-control @error('hire_date') is-invalid @enderror" required>
                                    @error('hire_date')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text">{{ __('Today or earlier. Dates in the future are not accepted.') }}</div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="dept_no" class="form-label fw-semibold">{{ __('Department') }}</label>
                                    <select id="dept_no" name="dept_no" class="form-select @error('dept_no') is-invalid @enderror" required>
                                        <option value="">{{ __('Choose a department...') }}</option>
                                        @foreach ($departments as $department)
                                            <option value="{{ $department->dept_no }}" @selected(old('dept_no') === $department->dept_no)>{{ $department->dept_name }}</option>
                                        @endforeach
                                    </select>
                                    @error('dept_no')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-sm-6">
                                    <label for="title" class="form-label fw-semibold">{{ __('Job title') }}</label>
                                    <input type="text" id="title" name="title" value="{{ old('title') }}" list="titleOptions" autocomplete="off"
                                        class="form-control @error('title') is-invalid @enderror" maxlength="{{ $rules::TITLE_MAX }}" required>
                                    <datalist id="titleOptions">
                                        @foreach ($titleOptions as $option)
                                            <option value="{{ $option }}"></option>
                                        @endforeach
                                    </datalist>
                                    @error('title')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-sm-6">
                                    <label for="salary" class="form-label fw-semibold">{{ __('Salary (USD per year)') }}</label>
                                    <input type="number" id="salary" name="salary" value="{{ old('salary') }}" step="1"
                                        min="{{ $rules::MIN_SALARY }}" max="{{ $rules::MAX_SALARY }}"
                                        class="form-control @error('salary') is-invalid @enderror" required>
                                    @error('salary')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <p class="small text-body-secondary mt-3 mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                {{ __('The employee number is assigned automatically (the next free number).') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-person-plus me-1"></i>{{ __('Hire employee') }}
                </button>
                <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
            </div>
        </form>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
