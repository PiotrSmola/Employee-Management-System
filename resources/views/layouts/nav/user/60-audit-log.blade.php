@can('view-audit-log')
    <li>
        <a class="dropdown-item" href="{{ route('audit-log.index') }}">
            <i class="bi bi-journal-text me-2"></i>{{ __('Audit log') }}
        </a>
    </li>
@endcan
