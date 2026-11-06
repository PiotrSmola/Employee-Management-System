@can('viewAny', App\Models\Department::class)
    <li class="nav-item">
        <a class="nav-link orange-after" href="{{ route('departments.index') }}">
            <i class="bi bi-diagram-3 me-1"></i>
            {{ __('Departments') }}
        </a>
    </li>
@endcan
