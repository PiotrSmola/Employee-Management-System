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
