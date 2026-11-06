@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('My exports') . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">{{ __('Employees') }}</a></li>
                <li class="breadcrumb-item active">{{ __('My exports') }}</li>
            </ol>
        </nav>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1 class="h3 mb-0">
                <i class="bi bi-cloud-download me-2 text-primary"></i>
                {{ __('My exports') }}
            </h1>
            <a href="{{ route('employees.index') }}" class="btn btn-outline-primary">
                <i class="bi bi-plus-lg me-1"></i>{{ __('New export from the directory') }}
            </a>
        </div>

        @include('layouts.flash')

        <p class="text-body-secondary">
            {{ __('Large exports (PDF with more than :pdf rows, CSV / Excel with more than :rows rows) are generated in the background and listed here. Files are deleted automatically after :days days.', [
                'pdf' => $pdfLimit,
                'rows' => $queueThreshold,
                'days' => $retentionDays,
            ]) }}
            @if ($hasUnfinished)
                <span class="d-block mt-1" id="exportsAutoRefresh">
                    <span class="spinner-border spinner-border-sm text-primary me-1" role="status"></span>
                    {{ __('This page refreshes automatically while an export is being generated.') }}
                </span>
            @endif
        </p>

        <div class="card shadow-sm table-card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-primary">
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">{{ __('Format') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="text-end">{{ __('Rows') }}</th>
                                <th scope="col" class="text-end">{{ __('Size') }}</th>
                                <th scope="col">{{ __('Requested') }}</th>
                                <th scope="col">{{ __('Finished') }}</th>
                                <th scope="col" class="text-end">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($exports as $export)
                                <tr>
                                    <td class="emp-number">{{ $export->id }}</td>
                                    <td>
                                        <i class="bi {{ $export->format->icon() }} me-1"></i>{{ $export->format->label() }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $export->statusBadgeClass() }}">{{ $export->statusLabel() }}</span>
                                        @if ($export->error)
                                            <div class="small text-danger mt-1">{{ $export->error }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $export->row_count !== null ? number_format($export->row_count) : '-' }}</td>
                                    <td class="text-end">
                                        {{ $export->humanFileSize() }}
                                    </td>
                                    <td>{{ $export->created_at->format('d.m.Y H:i') }}</td>
                                    <td>{{ $export->finished_at?->format('d.m.Y H:i') ?? '-' }}</td>
                                    <td class="text-end text-nowrap">
                                        @if ($export->isDownloadable())
                                            <a href="{{ route('exports.download', $export->id) }}" class="btn btn-sm btn-success">
                                                <i class="bi bi-download me-1"></i>{{ __('Download') }}
                                            </a>
                                        @endif
                                        <form method="POST" action="{{ route('exports.destroy', $export->id) }}" class="d-inline"
                                            onsubmit="return confirm('{{ __('Delete this export?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('Delete') }}">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                        {{ __('No exports yet. Large exports from the employee directory appear here.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($exports->hasPages())
                <div class="card-footer d-flex justify-content-end">
                    {{ $exports->links('pagination::bootstrap-5') }}
                </div>
            @endif
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])

    @if ($hasUnfinished)
        <script src="{{ asset('js/auto-reload.js') }}" data-delay="5000"></script>
    @endif
</body>

</html>
