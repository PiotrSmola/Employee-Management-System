@php($editing = $department->exists)
@include('layouts.html')
@include('layouts.head', ['pageTitle' => ($editing ? __('Rename department') : __('New department')) . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('departments.index') }}">{{ __('Departments') }}</a></li>
                @if ($editing)
                    <li class="breadcrumb-item"><a href="{{ route('departments.show', $department) }}">{{ $department->dept_name }}</a></li>
                @endif
                <li class="breadcrumb-item active">{{ $editing ? __('Rename') : __('New department') }}</li>
            </ol>
        </nav>

        <h1 class="h3 mb-4">
            <i class="bi bi-{{ $editing ? 'pencil' : 'plus-lg' }} me-2 text-primary"></i>
            {{ $editing ? __('Rename department') : __('New department') }}
        </h1>

        @include('layouts.flash')

        <div class="row">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-diagram-3 me-2"></i>{{ __('Department') }}</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ $editing ? route('departments.update', $department) : route('departments.store') }}" novalidate>
                            @csrf
                            @if ($editing)
                                @method('PUT')
                            @endif

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="dept_no" class="form-label fw-semibold">{{ __('Code') }}</label>
                                    @if ($editing)
                                        <input type="text" id="dept_no" value="{{ $department->dept_no }}" class="form-control" disabled>
                                        <div class="form-text">{{ __('The code cannot be changed.') }}</div>
                                    @else
                                        <input type="text" id="dept_no" name="dept_no" value="{{ old('dept_no', $department->dept_no) }}"
                                            class="form-control @error('dept_no') is-invalid @enderror" required maxlength="4"
                                            pattern="d[0-9]{3}" placeholder="d010">
                                        @error('dept_no')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                        <div class="form-text">{{ __('"d" and three digits, e.g. d010.') }}</div>
                                    @endif
                                </div>
                                <div class="col-md-8">
                                    <label for="dept_name" class="form-label fw-semibold">{{ __('Name') }}</label>
                                    <input type="text" id="dept_name" name="dept_name" value="{{ old('dept_name', $department->dept_name) }}"
                                        class="form-control @error('dept_name') is-invalid @enderror" required maxlength="40" autofocus>
                                    @error('dept_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i>{{ $editing ? __('Save changes') : __('Create department') }}
                                </button>
                                <a href="{{ $editing ? route('departments.show', $department) : route('departments.index') }}"
                                    class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
