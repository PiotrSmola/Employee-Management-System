@include('layouts.html')
@include('layouts.head', ['pageTitle' => 'Employee Management - All Employees'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        @include('layouts.flash')
        @livewire('employee-filter')
        @livewire('employee-list')
    </main>

    @include('layouts.footer', ['fixedBottom' => false])

    @can('export-data')
        @include('components.export-modal')
    @endcan

    <script src="{{ asset('js/url-history.js') }}"></script>

    @livewireScripts
</body>

</html>
