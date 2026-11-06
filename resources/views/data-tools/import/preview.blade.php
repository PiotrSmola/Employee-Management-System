@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Import preview') . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('employees.import.create') }}">{{ __('Import employees') }}</a></li>
                <li class="breadcrumb-item active">{{ __('Preview') }}</li>
            </ol>
        </nav>

        <h1 class="h3 mb-1">
            <i class="bi bi-clipboard-check me-2 text-primary"></i>
            {{ __('Import preview') }}
        </h1>
        <p class="text-body-secondary mb-4"><i class="bi bi-file-earmark me-1"></i>{{ $fileName }}</p>

        @include('layouts.flash')

        @foreach ($analysis['file_errors'] as $error)
            <div class="alert alert-danger"><i class="bi bi-x-octagon me-1"></i>{{ $error }}</div>
        @endforeach

        @if ($analysis['rows'] !== [])
            <div class="row g-3 mb-4">
                <div class="col-sm-4">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <div class="display-6">{{ number_format(count($analysis['rows'])) }}</div>
                            <div class="text-body-secondary">{{ __('rows') }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <div class="display-6 text-success">{{ number_format($analysis['valid']) }}</div>
                            <div class="text-body-secondary">{{ __('valid') }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <div class="display-6 {{ $analysis['invalid'] > 0 ? 'text-danger' : '' }}">{{ number_format($analysis['invalid']) }}</div>
                            <div class="text-body-secondary">{{ __('with errors') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="d-flex gap-2 mb-4 flex-wrap">
            @if ($canCommit)
                <form method="POST" action="{{ route('employees.import.commit', $token) }}">
                    @csrf
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check2-circle me-1"></i>
                        {{ __('Import :count employees', ['count' => number_format(count($analysis['rows']))]) }}
                    </button>
                </form>
            @else
                <div class="alert alert-warning mb-0 py-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    {{ __('Nothing can be imported until every row is valid. Correct the file and upload it again.') }}
                </div>
            @endif
            <form method="POST" action="{{ route('employees.import.cancel', $token) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="bi bi-x-lg me-1"></i>{{ $canCommit ? __('Cancel') : __('Upload another file') }}
                </button>
            </form>
        </div>

        @if ($invalidRows !== [])
            <div class="card mb-4 border-danger">
                <div class="card-header">
                    <h5 class="mb-0 text-danger"><i class="bi bi-bug me-2"></i>{{ __('Rows with errors') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle" id="importErrors">
                            <thead>
                                <tr>
                                    <th>{{ __('Row') }}</th>
                                    <th>{{ __('Employee') }}</th>
                                    <th>{{ __('Errors') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invalidRows as $row)
                                    <tr>
                                        <td class="fw-semibold">{{ $row['line'] }}</td>
                                        <td>{{ trim($row['values']['first_name'].' '.$row['values']['last_name']) ?: '-' }}</td>
                                        <td>
                                            <ul class="mb-0 ps-3 text-danger small">
                                                @foreach ($row['errors'] as $error)
                                                    <li>{{ $error }}</li>
                                                @endforeach
                                            </ul>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @if ($analysis['invalid'] > count($invalidRows))
                    <div class="card-footer small text-body-secondary">
                        {{ __('Showing the first :shown of :total rows with errors.', ['shown' => count($invalidRows), 'total' => number_format($analysis['invalid'])]) }}
                    </div>
                @endif
            </div>
        @endif

        @if ($sampleRows !== [])
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0 text-body-emphasis"><i class="bi bi-table me-2"></i>{{ __('Data') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="importRows">
                            <thead class="table-primary">
                                <tr>
                                    <th>{{ __('Row') }}</th>
                                    @foreach ($columns as $label)
                                        <th>{{ __($label) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sampleRows as $row)
                                    <tr class="{{ $row['errors'] !== [] ? 'table-danger' : '' }}">
                                        <td>{{ $row['line'] }}</td>
                                        @foreach (array_keys($columns) as $column)
                                            <td>{{ $row['values'][$column] }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @if (count($analysis['rows']) > count($sampleRows))
                    <div class="card-footer small text-body-secondary">
                        {{ __('Showing the first :shown of :total rows.', ['shown' => count($sampleRows), 'total' => number_format(count($analysis['rows']))]) }}
                    </div>
                @endif
            </div>
        @endif
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
