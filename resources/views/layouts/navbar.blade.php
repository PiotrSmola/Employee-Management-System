<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold orange-after" href="{{ route('index') }}">
            <i class="bi bi-people-fill me-2"></i>
            <b>Employee Management</b>
        </a>

        <div class="collapse navbar-collapse" id="navbarScroll">
            <ul class="navbar-nav me-auto my-2 my-lg-0">
                <li class="nav-item">
                    <a class="nav-link orange-after" href="{{ route('index') }}">
                        <i class="bi bi-house me-1"></i>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link orange-after" href="{{ route('employees.index') }}">
                        <i class="bi bi-list-ul me-1"></i>
                        All Employees
                    </a>
                </li>
                @can('export-data')
                    <li class="nav-item">
                        @if (Route::currentRouteName() === 'employees.index')
                            <a class="nav-link orange-after" href="#" data-bs-toggle="modal"
                                data-bs-target="#exportModal">
                                <i class="bi bi-download me-1"></i>
                                Export Data
                            </a>
                        @else
                            <a class="nav-link orange-after" href="{{ route('employees.index') }}"
                                title="Go to employees page to access export">
                                <i class="bi bi-download me-1"></i>
                                Export Data
                            </a>
                        @endif
                    </li>
                @endcan
                @can('manage-users')
                    <li class="nav-item">
                        <a class="nav-link orange-after" href="{{ route('users.index') }}">
                            <i class="bi bi-person-gear me-1"></i>
                            {{ __('Users') }}
                        </a>
                    </li>
                @endcan
            </ul>
            <ul class="navbar-nav align-items-lg-center">
                <li class="nav-item">
                    <button class="nav-link btn" id="theme-toggle" type="button" title="{{ __('Toggle theme') }}">
                        <i class="bi bi-moon-stars" id="theme-icon"></i>
                    </button>
                </li>
                @auth
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userMenu"
                            role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle me-2 fs-5"></i>
                            <span class="me-2">{{ auth()->user()->name }}</span>
                            <span class="badge role-badge {{ auth()->user()->role->badgeClass() }}">
                                {{ auth()->user()->role->label() }}
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userMenu">
                            <li class="px-3 py-2 small text-body-secondary">
                                <div class="fw-semibold text-body">{{ auth()->user()->name }}</div>
                                <div>{{ auth()->user()->email }}</div>
                                @if (auth()->user()->role->isDepartmentScoped())
                                    <div>
                                        <i class="bi bi-diagram-3 me-1"></i>
                                        {{ auth()->user()->department?->dept_name ?? __('No department assigned') }}
                                    </div>
                                @endif
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="{{ route('account.edit') }}">
                                    <i class="bi bi-person me-2"></i>{{ __('My account') }}
                                </a>
                            </li>
                            @can('manage-users')
                                <li>
                                    <a class="dropdown-item" href="{{ route('users.index') }}">
                                        <i class="bi bi-people me-2"></i>{{ __('Users') }}
                                    </a>
                                </li>
                            @endcan
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-box-arrow-right me-2"></i>{{ __('Log out') }}
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </li>
                @endauth
            </ul>
        </div>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarScroll"
            aria-controls="navbarScroll" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
    </div>
</nav>
