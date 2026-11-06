@php
    // Columns the user may choose (no salary columns without view-salaries - enforced again on the server).
    $exportColumns = \App\Services\EmployeeExportService::availableColumns(Gate::allows('view-salaries'));
    $exportFormats = [
        'csv' => ['label' => 'CSV', 'icon' => 'bi-filetype-csv', 'color' => 'success', 'queueAbove' => (int) config('data_tools.exports.queue_threshold')],
        'xlsx' => ['label' => 'Excel (XLSX)', 'icon' => 'bi-file-earmark-excel', 'color' => 'success', 'queueAbove' => (int) config('data_tools.exports.queue_threshold')],
        'pdf' => ['label' => 'PDF', 'icon' => 'bi-file-earmark-pdf', 'color' => 'danger', 'queueAbove' => \App\Services\EmployeeExportService::PDF_LIMIT],
    ];
@endphp
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exportModalLabel">
                    <i class="bi bi-download me-2 text-primary"></i>
                    Export Selected Employee Data
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <span id="selectedCountText">Loading selected employees...</span>
                </div>

                <div class="row g-3">
                    @foreach ($exportFormats as $format => $option)
                        <div class="col-md-4">
                            <div class="card h-100 export-option" data-format="{{ $format }}">
                                <div class="card-body text-center">
                                    <i class="bi {{ $option['icon'] }} display-4 text-{{ $option['color'] }} mb-3"></i>
                                    <h6 class="card-title">{{ $option['label'] }}</h6>
                                    <button type="button" class="btn btn-{{ $option['color'] }} mt-2 export-format-btn"
                                        data-format="{{ $format }}" data-label="{{ __('Export') }} {{ $format === 'xlsx' ? 'Excel' : strtoupper($format) }}"
                                        data-queue-above="{{ $option['queueAbove'] }}">
                                        <i class="bi bi-download me-1"></i> {{ __('Export') }} {{ $format === 'xlsx' ? 'Excel' : strtoupper($format) }}
                                    </button>
                                    <div class="small text-body-secondary mt-2 export-queue-hint d-none">
                                        <i class="bi bi-hourglass-split me-1"></i>{{ __('Generated in the background (My exports)') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 text-body-emphasis"><i class="bi bi-layout-three-columns me-1"></i>{{ __('Columns') }}</h6>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" id="exportColumnsAll">{{ __('All') }}</button>
                            <button type="button" class="btn btn-outline-secondary" id="exportColumnsNone">{{ __('None') }}</button>
                        </div>
                    </div>
                    <div class="row g-2" id="exportColumns">
                        @foreach ($exportColumns as $key => $label)
                            <div class="col-6 col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input export-column" type="checkbox" value="{{ $key }}"
                                        id="exportColumn-{{ $key }}" checked>
                                    <label class="form-check-label" for="exportColumn-{{ $key }}">{{ $label }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="form-text">
                        {{ __('The chosen columns apply to every format. PDFs with more than :pdf rows and CSV / Excel files with more than :rows rows are generated in the background and listed under My exports.', [
                            'pdf' => number_format(\App\Services\EmployeeExportService::PDF_LIMIT),
                            'rows' => number_format((int) config('data_tools.exports.queue_threshold')),
                        ]) }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const formatButtons = Array.from(document.querySelectorAll('.export-format-btn'));
        const exportModal = document.getElementById('exportModal');
        const selectedCountText = document.getElementById('selectedCountText');
        const columnInputs = Array.from(document.querySelectorAll('.export-column'));
        const COLUMNS_KEY = 'ems.export.columns';
        let selectedCount = 0;

        // Remember the column choice in this browser (best effort).
        try {
            const saved = JSON.parse(window.localStorage.getItem(COLUMNS_KEY) || 'null');
            if (Array.isArray(saved) && saved.length > 0) {
                columnInputs.forEach(input => input.checked = saved.includes(input.value));
            }
        } catch (e) {}

        function chosenColumns() {
            return columnInputs.filter(input => input.checked).map(input => input.value);
        }

        function refreshButtons() {
            const hasSelection = selectedCount > 0;
            const hasColumns = chosenColumns().length > 0;

            formatButtons.forEach(btn => {
                btn.disabled = !hasSelection || !hasColumns;
                btn.innerHTML = `<i class="bi bi-download me-1"></i> ${!hasSelection ? 'No Selection' : (!hasColumns ? 'No Columns' : btn.dataset.label)}`;
                const hint = btn.parentElement.querySelector('.export-queue-hint');
                hint.classList.toggle('d-none', !(selectedCount > parseInt(btn.dataset.queueAbove, 10)));
            });
        }

        columnInputs.forEach(input => input.addEventListener('change', () => {
            try {
                window.localStorage.setItem(COLUMNS_KEY, JSON.stringify(chosenColumns()));
            } catch (e) {}
            refreshButtons();
        }));
        document.getElementById('exportColumnsAll').addEventListener('click', () => {
            columnInputs.forEach(input => input.checked = true);
            columnInputs[0]?.dispatchEvent(new Event('change'));
        });
        document.getElementById('exportColumnsNone').addEventListener('click', () => {
            columnInputs.forEach(input => input.checked = false);
            columnInputs[0]?.dispatchEvent(new Event('change'));
        });

        function waitForLivewire(callback) {
            if (typeof window.Livewire !== 'undefined' && window.Livewire.find && window.Livewire.all) {
                callback();
            } else {
                setTimeout(() => waitForLivewire(callback), 150);
            }
        }

        exportModal.addEventListener('show.bs.modal', function() {
            waitForLivewire(() => {
                updateSelectedCount();
            });
        });

        function findLivewireComponentByName(nameToFind) {
            if (!window.Livewire || !window.Livewire.all) {
                return null;
            }
            const allComponents = window.Livewire.all();
            for (let i = 0; i < allComponents.length; i++) {
                const componentFromAll = allComponents[i];
                let identifiedName = null;
                let componentId = componentFromAll.id;

                if (componentFromAll.fingerprint && componentFromAll.fingerprint.name) {
                    identifiedName = componentFromAll.fingerprint.name;
                } else if (componentFromAll.name) {
                    identifiedName = componentFromAll.name;
                }

                if (identifiedName === nameToFind && componentId) {
                    try {
                        const componentInstance = window.Livewire.find(componentId);
                        return componentInstance || componentFromAll;
                    } catch (e) {
                        console.error(
                            `Error calling Livewire.find('${componentId}') for component '${nameToFind}':`,
                            e);
                        return componentFromAll;
                    }
                }
            }
            return null;
        }

        async function updateSelectedCount() {
            const employeeListComponent = findLivewireComponentByName('employee-list');

            if (!employeeListComponent || typeof employeeListComponent.call !== 'function') {
                selectedCountText.innerHTML =
                    `<span class="text-danger">Error: EmployeeList component not found.</span>`;
                handleFallbackForCount();
                return;
            }

            try {
                selectedCount = await employeeListComponent.call('getSelectedCount');
                selectedCountText.innerHTML =
                    `<strong>${Number(selectedCount).toLocaleString()}</strong> employees will be exported.`;
            } catch (error) {
                selectedCountText.innerHTML =
                    `<span class="text-danger">Error: Failed to get selection details. ${error.message}</span>`;
                handleFallbackForCount();
                return;
            }

            refreshButtons();
        }

        function handleFallbackForCount() {
            const exportTriggerButton = document.getElementById('exportTriggerBtn');
            selectedCount = 0;
            if (exportTriggerButton) {
                const countSpan = exportTriggerButton.querySelector('#exportCountDisplay');
                if (countSpan && countSpan.textContent) {
                    selectedCount = parseInt(countSpan.textContent) || 0;
                }
            }

            selectedCountText.innerHTML =
                `<strong>${selectedCount}</strong> employees will be exported. <small class="text-warning">(display might be approximate)</small>`;
            refreshButtons();
        }

        async function getExportPayload() {
            const listComponent = findLivewireComponentByName('employee-list');

            if (!listComponent || typeof listComponent.call !== 'function') {
                throw new Error('Employee list component not found.');
            }

            return await listComponent.call('getExportPayload');
        }

        function addHiddenInput(form, name, value) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }

        async function handleDownload(btn) {
            const format = btn.dataset.format;

            try {
                const payload = await getExportPayload();

                if (!payload || payload.selectedCount === 0) {
                    alert('Please select at least one employee to export.');
                    return;
                }

                btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Generating...';
                btn.disabled = true;

                const form = document.createElement('form');
                form.action = '{{ route('employees.export') }}';
                form.method = 'POST';
                form.style.display = 'none';

                addHiddenInput(form, '_token', '{{ csrf_token() }}');
                addHiddenInput(form, 'format', format);
                addHiddenInput(form, 'columns', chosenColumns().join(','));
                addHiddenInput(form, 'selectAll', payload.selectAll ? '1' : '0');

                // The selection is sent as "all matching except ..." or "only these",
                // never as the full list of ids. The ids go into ONE comma-separated field:
                // PHP silently drops request fields beyond max_input_vars (1000 by default),
                // so one input per id would lose ids and export deselected employees.
                const ids = payload.selectAll ? payload.excludedEmployees : payload.selectedEmployees;
                const idsField = payload.selectAll ? 'excludedEmployees' : 'selectedEmployees';
                addHiddenInput(form, idsField, (ids || []).join(','));

                ['search', 'employmentStatus', 'gender', 'department', 'jobTitle', 'salaryMin', 'salaryMax',
                    'sortBy', 'sortDirection'
                ].forEach(key => {
                    const value = payload[key];
                    if (value !== '' && value !== null && typeof value !== 'undefined') {
                        addHiddenInput(form, key, value);
                    }
                });

                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);

                const modalInstance = bootstrap.Modal.getInstance(exportModal);
                if (modalInstance) {
                    modalInstance.hide();
                }

                // Buttons are refreshed when the modal is shown the next time.
                setTimeout(refreshButtons, 2000);

            } catch (error) {
                console.error('Export error:', error.message);
                alert('An error occurred during export. Please try again. Details: ' + error.message);
                updateSelectedCount();
            }
        }

        formatButtons.forEach(btn => btn.addEventListener('click', () => handleDownload(btn)));
    });
</script>
