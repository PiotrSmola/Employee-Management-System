@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Sign in') . ' - Employee Management System'])

<body>
    <button class="btn btn-outline-secondary auth-theme-toggle" id="theme-toggle" type="button"
        title="{{ __('Toggle theme') }}">
        <i class="bi bi-moon-stars" id="theme-icon"></i>
    </button>

    <main class="auth-page">
        <div class="card auth-card shadow-lg">
            <div class="auth-brand">
                <i class="bi bi-people-fill display-5 d-block mb-2"></i>
                <h1 class="h4 fw-bold mb-1">Employee Management System</h1>
                <p class="mb-0 opacity-75 small">{{ __('Sign in to continue') }}</p>
            </div>

            <div class="card-body p-4">
                <form method="POST" action="{{ route('login.store') }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">{{ __('E-mail') }}</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" id="email" name="email" value="{{ old('email') }}"
                                class="form-control @error('email') is-invalid @enderror" autocomplete="username"
                                required autofocus>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">{{ __('Password') }}</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" id="password" name="password"
                                class="form-control @error('password') is-invalid @enderror"
                                autocomplete="current-password" required>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1"
                            @checked(old('remember'))>
                        <label class="form-check-label" for="remember">{{ __('Remember me') }}</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 btn-lg">
                        <i class="bi bi-box-arrow-in-right me-1"></i>
                        {{ __('Sign in') }}
                    </button>
                </form>

                <p class="text-body-secondary small text-center mt-4 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    {{ __('No account or forgot your password? Ask an administrator.') }}
                </p>
            </div>
        </div>
    </main>
</body>

</html>
