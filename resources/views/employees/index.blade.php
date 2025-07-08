@include('layouts.html')
@include('layouts.head', ['pageTitle' => 'Employee Management - All Employees'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        @livewire('employee-filter')
        @livewire('employee-list')
    </main>

    @include('layouts.footer', ['fixedBottom' => false])

    @include('components.export-modal')

    @livewireScripts

    <script>
        document.addEventListener('livewire:initialized', function() {
            function updateExportButton() {
                const exportBtn = document.getElementById('exportTriggerBtn');
                const countDisplay = document.getElementById('exportCountDisplay');

            }

            Livewire.on('livewire:updated', function(event) {
                updateExportButton();
            });

            updateExportButton();
        });
    </script>
</body>

</html>
