@php
    $editing = $user->exists;
    $self = $editing && $user->is(auth()->user());
    $selectedRole = old('role', $user->role?->value);
@endphp
@include('layouts.html')
@include('layouts.head', ['pageTitle' => ($editing ? __('Edit user') : __('New user')) . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('users.index') }}">{{ __('Users') }}</a></li>
                <li class="breadcrumb-item active">{{ $editing ? $user->name : __('New user') }}</li>
            </ol>
        </nav>

        <h1 class="h3 mb-4">
            <i class="bi bi-{{ $editing ? 'person-gear' : 'person-plus' }} me-2 text-primary"></i>
            {{ $editing ? __('Edit user') : __('New user') }}
        </h1>

        @include('layouts.flash')

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-person-vcard me-2"></i>{{ __('Account') }}</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" novalidate>
                            @csrf
                            @if ($editing)
                                @method('PUT')
                            @endif

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="name" class="form-label fw-semibold">{{ __('Full name') }}</label>
                                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                                        class="form-control @error('name') is-invalid @enderror" required maxlength="255">
                                    @error('name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="email" class="form-label fw-semibold">{{ __('E-mail') }}</label>
                                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}"
                                        class="form-control @error('email') is-invalid @enderror" required maxlength="255">
                                    @error('email')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-6">
                                    <label for="role" class="form-label fw-semibold">{{ __('Role') }}</label>
                                    @if ($self)
                                        {{-- The own role cannot be changed (also enforced server-side). --}}
                                        <input type="hidden" name="role" value="{{ $user->role->value }}">
                                    @endif
                                    <select id="role" @unless ($self) name="role" @endunless
                                        class="form-select @error('role') is-invalid @enderror" @disabled($self) required>
                                        @foreach (\App\Enums\Role::cases() as $option)
                                            <option value="{{ $option->value }}" @selected($selectedRole === $option->value)>
                                                {{ $option->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('role')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-6" id="departmentField">
                                    <label for="dept_no" class="form-label fw-semibold">{{ __('Department') }}</label>
                                    <select id="dept_no" name="dept_no" class="form-select @error('dept_no') is-invalid @enderror">
                                        <option value="">{{ __('Choose a department...') }}</option>
                                        @foreach ($departments as $department)
                                            <option value="{{ $department->dept_no }}" @selected(old('dept_no', $user->dept_no) === $department->dept_no)>
                                                {{ $department->dept_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('dept_no')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text">{{ __('A manager only sees the employees of this department.') }}</div>
                                </div>

                                @unless ($editing)
                                    <div class="col-md-6">
                                        <label for="password" class="form-label fw-semibold">{{ __('Password') }}</label>
                                        <input type="password" id="password" name="password" autocomplete="new-password"
                                            class="form-control @error('password') is-invalid @enderror" required>
                                        @error('password')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                        <div class="form-text">{{ __('At least 8 characters.') }}</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="password_confirmation" class="form-label fw-semibold">{{ __('Confirm password') }}</label>
                                        <input type="password" id="password_confirmation" name="password_confirmation"
                                            autocomplete="new-password" class="form-control" required>
                                    </div>
                                @endunless

                                <div class="col-12">
                                    @if ($self)
                                        <input type="hidden" name="is_active" value="1">
                                    @else
                                        <input type="hidden" name="is_active" value="0">
                                    @endif
                                    <div class="form-check form-switch">
                                        <input class="form-check-input @error('is_active') is-invalid @enderror" type="checkbox"
                                            role="switch" id="is_active" @unless ($self) name="is_active" @endunless value="1"
                                            @checked(old('is_active', $user->is_active ?? true)) @disabled($self)>
                                        <label class="form-check-label" for="is_active">{{ __('Active (can sign in)') }}</label>
                                        @error('is_active')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    @if ($self)
                                        <div class="form-text">{{ __('You cannot change your own role or deactivate your own account.') }}</div>
                                    @endif
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i>{{ $editing ? __('Save changes') : __('Create user') }}
                                </button>
                                <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                @if ($editing)
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0 text-body-emphasis"><i class="bi bi-key me-2"></i>{{ __('Reset password') }}</h5>
                        </div>
                        <div class="card-body">
                            <p class="small text-body-secondary">
                                {{ __('Sets a new password and signs the user out of their other sessions. Give the new password to the user in person.') }}
                            </p>
                            <form method="POST" action="{{ route('users.password', $user) }}">
                                @csrf
                                @method('PUT')
                                <div class="mb-3">
                                    <label for="new_password" class="form-label fw-semibold">{{ __('New password') }}</label>
                                    <input type="password" id="new_password" name="password" autocomplete="new-password"
                                        class="form-control @error('password', 'resetPassword') is-invalid @enderror" required>
                                    @error('password', 'resetPassword')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="new_password_confirmation" class="form-label fw-semibold">{{ __('Confirm new password') }}</label>
                                    <input type="password" id="new_password_confirmation" name="password_confirmation"
                                        autocomplete="new-password" class="form-control" required>
                                </div>
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-shield-lock me-1"></i>{{ __('Reset password') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endif

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-shield-check me-2"></i>{{ __('Roles') }}</h5>
                    </div>
                    <ul class="list-group list-group-flush">
                        @foreach (\App\Enums\Role::cases() as $option)
                            <li class="list-group-item">
                                <span class="badge role-badge {{ $option->badgeClass() }} me-2">{{ $option->label() }}</span>
                                <span class="small">{{ $option->description() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const role = document.getElementById('role');
            const departmentField = document.getElementById('departmentField');
            const toggle = () => departmentField.classList.toggle('d-none', role.value !== 'manager');
            role.addEventListener('change', toggle);
            toggle();
        });
    </script>
</body>

</html>
