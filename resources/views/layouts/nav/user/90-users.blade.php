@can('manage-users')
    <li>
        <a class="dropdown-item" href="{{ route('users.index') }}">
            <i class="bi bi-people me-2"></i>{{ __('Users') }}
        </a>
    </li>
@endcan
