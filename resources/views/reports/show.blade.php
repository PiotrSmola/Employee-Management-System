@php
    $isCompensation = $section === \App\Reports\ReportCatalog::COMPENSATION;
    $pageName = $isCompensation ? __('Compensation') : __('Workforce');
    // Reports drawn at half width on large screens; the others use the full width.
    $halfWidth = ['tenure', 'age', 'salary-departments', 'salary-titles', 'salary-distribution', 'salary-growth'];
@endphp
@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Reports') . ' - ' . $pageName . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4 reports-page" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('reports.index', $context->query()) }}">{{ __('Reports') }}</a></li>
                <li class="breadcrumb-item active">{{ $pageName }}</li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <h1 class="h3 mb-0">
                <i class="bi bi-bar-chart me-2 text-primary"></i>{{ __('Reports') }}
            </h1>
            @if ($context->isDepartmentScoped())
                <span class="badge text-bg-warning role-badge">
                    <i class="bi bi-diagram-3 me-1"></i>
                    {{ __('Your department: :name', ['name' => $context->department ? ($departments[$context->department] ?? $context->department) : __('none assigned')]) }}
                </span>
            @endif
        </div>

        <ul class="nav nav-tabs mb-3 reports-tabs">
            <li class="nav-item">
                <a class="nav-link {{ $isCompensation ? '' : 'active' }}" href="{{ route('reports.workforce', $context->query()) }}"
                    @unless ($isCompensation) aria-current="page" @endunless>
                    <i class="bi bi-people me-1"></i>{{ __('Workforce') }}
                </a>
            </li>
            @can('view-salaries')
                <li class="nav-item">
                    <a class="nav-link {{ $isCompensation ? 'active' : '' }}" href="{{ route('reports.compensation', $context->query()) }}"
                        @if ($isCompensation) aria-current="page" @endif>
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

        @include($isCompensation ? 'reports.partials.summary-compensation' : 'reports.partials.summary-workforce')

        <div class="row g-4">
            @foreach ($tables as $table)
                <div class="{{ in_array($table->key, $halfWidth, true) ? 'col-xl-6' : 'col-12' }}">
                    @include('reports.partials.card', ['table' => $table])
                </div>
            @endforeach
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
    @include('reports.partials.scripts')
</body>

</html>
