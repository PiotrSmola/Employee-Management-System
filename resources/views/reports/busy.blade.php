@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Reports') . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-5" style="flex: 1;">
        <div class="alert alert-info">
            <i class="bi bi-hourglass-split me-2"></i>
            {{ __('These figures are being prepared. Please try again in about :seconds seconds.', ['seconds' => $retryAfter]) }}
        </div>
        <a class="btn btn-primary" href="{{ url()->full() }}">
            <i class="bi bi-arrow-clockwise me-1"></i>{{ __('Try again') }}
        </a>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
