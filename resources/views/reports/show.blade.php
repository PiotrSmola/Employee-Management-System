@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Reports') . ' - ' . $page->name() . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4 reports-page" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('reports.index', $context->query()) }}">{{ __('Reports') }}</a></li>
                <li class="breadcrumb-item active">{{ $page->name() }}</li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <h1 class="h3 mb-0">
                <i class="bi bi-bar-chart me-2 text-primary"></i>{{ __('Reports') }}
            </h1>
            @if ($context->isDepartmentScoped())
                <span class="badge text-bg-warning role-badge">
                    <i class="bi bi-diagram-3 me-1"></i>
                    {{ $page->scopeLabel() }}
                </span>
            @endif
        </div>

        <ul class="nav nav-tabs mb-3 reports-tabs">
            <li class="nav-item">
                <a class="nav-link @unless ($page->isCompensation()) active @endunless" href="{{ route('reports.workforce', $context->query()) }}"
                    @unless ($page->isCompensation()) aria-current="page" @endunless>
                    <i class="bi bi-people me-1"></i>{{ __('Workforce') }}
                </a>
            </li>
            @can('view-salaries')
                <li class="nav-item">
                    <a class="nav-link @if ($page->isCompensation()) active @endif" href="{{ route('reports.compensation', $context->query()) }}"
                        @if ($page->isCompensation()) aria-current="page" @endif>
                        <i class="bi bi-currency-dollar me-1"></i>{{ __('Compensation') }}
                    </a>
                </li>
            @endcan
        </ul>

        @include('reports.partials.filters')

        @foreach ($inputErrors as $message)
            <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-2"></i>{{ $message }}</div>
        @endforeach

        @if ($context->seesNobody())
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>{{ __('No department is assigned to your account, so there is nothing to report.') }}
            </div>
        @endif

        <div class="row g-3 mb-4">
            @foreach ($page->tiles() as $tile)
                @include('reports.partials.tile', $tile)
            @endforeach
        </div>

        <div class="row g-4">
            @foreach ($tables as $table)
                <div class="{{ $table->widthClass() }}">
                    @include('reports.partials.card', ['table' => $table])
                </div>
            @endforeach
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
    @include('reports.partials.scripts')
</body>

</html>
