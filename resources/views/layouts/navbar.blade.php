<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold orange-after" href="{{ route('index') }}">
            <i class="bi bi-people-fill me-2"></i>
            <b>Employee Management</b>
        </a>

        <div class="collapse navbar-collapse" id="navbarScroll">
            <ul class="navbar-nav me-auto my-2 my-lg-0 navbar-nav-scroll" style="--bs-scroll-height: 100px;">
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
            </ul>
            <ul class="navbar-nav">
                <li class="pr-5">
                    <button class="nav-link btn" id="theme-toggle">
                        <i class="bi bi-moon-stars" id="theme-icon"></i>
                    </button>
                </li>
            </ul>
        </div>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarScroll"
            aria-controls="navbarScroll" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
    </div>
</nav>
