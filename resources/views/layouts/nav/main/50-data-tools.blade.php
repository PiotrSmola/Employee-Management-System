@can('export-data')
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle orange-after" href="#" id="dataToolsMenu" role="button"
            data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-database-gear me-1"></i>
            {{ __('Data tools') }}
        </a>
        <ul class="dropdown-menu shadow" aria-labelledby="dataToolsMenu">
            <li>
                <a class="dropdown-item" href="{{ route('exports.index') }}">
                    <i class="bi bi-cloud-download me-2"></i>{{ __('My exports') }}
                </a>
            </li>
        </ul>
    </li>
@endcan
