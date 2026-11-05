@can('manage-users')
    <li class="nav-item">
        <a class="nav-link orange-after" href="{{ route('users.index') }}">
            <i class="bi bi-person-gear me-1"></i>
            {{ __('Users') }}
        </a>
    </li>
@endcan
