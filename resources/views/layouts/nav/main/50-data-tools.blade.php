@canany(['export-data', 'import-data'])
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle orange-after" href="#" id="dataToolsMenu" role="button"
            data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-database-gear me-1"></i>
            {{ __('Data tools') }}
        </a>
        <ul class="dropdown-menu shadow" aria-labelledby="dataToolsMenu">
            @can('export-data')
                <li>
                    <a class="dropdown-item" href="{{ route('exports.index') }}">
                        <i class="bi bi-cloud-download me-2"></i>{{ __('My exports') }}
                    </a>
                </li>
            @endcan
            @can('import-data')
                <li>
                    <a class="dropdown-item" href="{{ route('employees.import.create') }}">
                        <i class="bi bi-upload me-2"></i>{{ __('Import employees') }}
                    </a>
                </li>
            @endcan
        </ul>
    </li>
@endcanany
