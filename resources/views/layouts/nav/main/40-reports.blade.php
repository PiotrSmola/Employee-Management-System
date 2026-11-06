@can('view-reports')
    <li class="nav-item">
        <a class="nav-link orange-after" href="{{ route('reports.index') }}">
            <i class="bi bi-bar-chart me-1"></i>
            {{ __('Reports') }}
        </a>
    </li>
@endcan
