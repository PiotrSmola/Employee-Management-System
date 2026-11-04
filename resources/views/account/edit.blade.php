@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('My account') . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">{{ __('My account') }}</li>
            </ol>
        </nav>

        <h1 class="h3 mb-4">
            <i class="bi bi-person-circle me-2 text-primary"></i>
            {{ __('My account') }}
        </h1>

        @include('layouts.flash')

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-info-circle me-2"></i>{{ __('Account') }}</h5>
                    </div>
                    <div class="card-body">
                        <dl class="mb-0">
                            <dt>{{ __('E-mail') }}</dt>
                            <dd>{{ $user->email }}</dd>
                            <dt>{{ __('Role') }}</dt>
                            <dd>
                                <span class="badge role-badge {{ $user->role->badgeClass() }}">{{ $user->role->label() }}</span>
                                <div class="small text-body-secondary mt-1">{{ $user->role->description() }}</div>
                            </dd>
                            @if ($user->role->isDepartmentScoped())
                                <dt>{{ __('Department') }}</dt>
                                <dd>{{ $user->department?->dept_name ?? __('No department assigned') }}</dd>
                            @endif
                            <dt>{{ __('Last sign-in') }}</dt>
                            <dd class="mb-0">{{ $user->last_login_at?->format('d.m.Y H:i') ?? '-' }}</dd>
                        </dl>
                        <p class="small text-body-secondary mt-3 mb-0">
                            {{ __('E-mail, role and department are managed by an administrator.') }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-pencil me-2"></i>{{ __('Name') }}</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('account.profile') }}">
                            @csrf
                            @method('PUT')
                            <div class="mb-3">
                                <label for="name" class="form-label fw-semibold">{{ __('Full name') }}</label>
                                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                                    class="form-control @error('name', 'profile') is-invalid @enderror" required
                                    maxlength="255" autocomplete="name">
                                @error('name', 'profile')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>{{ __('Save') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-key me-2"></i>{{ __('Change password') }}</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('account.password') }}">
                            @csrf
                            @method('PUT')
                            <div class="mb-3">
                                <label for="current_password" class="form-label fw-semibold">{{ __('Current password') }}</label>
                                <input type="password" id="current_password" name="current_password"
                                    class="form-control @error('current_password', 'password') is-invalid @enderror"
                                    required autocomplete="current-password">
                                @error('current_password', 'password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label fw-semibold">{{ __('New password') }}</label>
                                <input type="password" id="password" name="password"
                                    class="form-control @error('password', 'password') is-invalid @enderror"
                                    required autocomplete="new-password">
                                @error('password', 'password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-text">{{ __('At least 8 characters.') }}</div>
                            </div>
                            <div class="mb-3">
                                <label for="password_confirmation" class="form-label fw-semibold">{{ __('Confirm new password') }}</label>
                                <input type="password" id="password_confirmation" name="password_confirmation"
                                    class="form-control" required autocomplete="new-password">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-shield-lock me-1"></i>{{ __('Change password') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
