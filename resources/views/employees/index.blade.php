@include('layouts.html')
@include('layouts.head', ['pageTitle' => 'Employee Management - All Employees'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        @livewire('employee-filter')
        @livewire('employee-list')
    </main>

    @include('layouts.footer', ['fixedBottom' => false])

    @can('export-data')
        @include('components.export-modal')
    @endcan

    @livewireScripts
</body>

</html>
