<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
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
                    <div class="col-md-6">
                        <div class="card h-100 export-option" data-format="csv">
                            <div class="card-body text-center">
                                <i class="bi bi-filetype-csv display-4 text-success mb-3"></i>
                                <h6 class="card-title">CSV Format</h6>
                                <button type="button" class="btn btn-success mt-2" id="exportCsvBtn">
                                    <i class="bi bi-download me-1"></i> Export CSV
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 export-option" data-format="pdf">
                            <div class="card-body text-center">
                                <i class="bi bi-file-earmark-pdf display-4 text-danger mb-3"></i>
                                <h6 class="card-title">PDF Format</h6>
                                <button type="button" class="btn btn-danger mt-2" id="exportPdfBtn">
                                    <i class="bi bi-download me-1"></i> Export PDF
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const csvBtn = document.getElementById('exportCsvBtn');
        const pdfBtn = document.getElementById('exportPdfBtn');
        const exportModal = document.getElementById('exportModal');
        const selectedCountText = document.getElementById('selectedCountText');

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

            if (!employeeListComponent) {
                selectedCountText.innerHTML =
                    `<span class="text-danger">Error: EmployeeList component not found.</span>`;
                csvBtn.disabled = true;
                pdfBtn.disabled = true;
                csvBtn.innerHTML = '<i class="bi bi-download me-1"></i> Error';
                pdfBtn.innerHTML = '<i class="bi bi-download me-1"></i> Error';
                handleFallbackForCount();
                return;
            }

            if (typeof employeeListComponent.call !== 'function') {
                selectedCountText.innerHTML =
                    `<span class="text-danger">Error: Component 'employee-list' is not callable.</span>`;
                handleFallbackForCount();
                return;
            }

            try {
                const selectedCount = await employeeListComponent.call('getSelectedCount');
                selectedCountText.innerHTML =
                    `<strong>${selectedCount}</strong> employees will be exported.`;
                const hasSelection = selectedCount > 0;

                csvBtn.disabled = !hasSelection;
                pdfBtn.disabled = !hasSelection;
                csvBtn.innerHTML =
                    `<i class="bi bi-download me-1"></i> ${hasSelection ? 'Export CSV' : 'No Selection'}`;
                pdfBtn.innerHTML =
                    `<i class="bi bi-download me-1"></i> ${hasSelection ? 'Export PDF' : 'No Selection'}`;

            } catch (error) {
                selectedCountText.innerHTML =
                    `<span class="text-danger">Error: Failed to get selection details. ${error.message}</span>`;
                handleFallbackForCount();
            }
        }

        function handleFallbackForCount() {
            const exportTriggerButton = document.getElementById('exportTriggerBtn');
            let count = 0;
            if (exportTriggerButton) {
                const countSpan = exportTriggerButton.querySelector('#exportCountDisplay');
                if (countSpan && countSpan.textContent) {
                    count = parseInt(countSpan.textContent) || 0;
                }
            }

            selectedCountText.innerHTML =
                `<strong>${count}</strong> employees will be exported. <small class="text-warning">(display might be approximate)</small>`;
            const hasSelection = count > 0;
            csvBtn.disabled = !hasSelection;
            pdfBtn.disabled = !hasSelection;
            csvBtn.innerHTML =
                `<i class="bi bi-download me-1"></i> ${hasSelection ? 'Export CSV' : 'No Selection'}`;
            pdfBtn.innerHTML =
                `<i class="bi bi-download me-1"></i> ${hasSelection ? 'Export PDF' : 'No Selection'}`;
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

        async function handleDownload(format) {
            try {
                const payload = await getExportPayload();

                if (!payload || payload.selectedCount === 0) {
                    alert('Please select at least one employee to export.');
                    return;
                }

                const btn = format === 'csv' ? csvBtn : pdfBtn;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Generating...';
                btn.disabled = true;

                const form = document.createElement('form');
                form.action = '{{ route('employees.export') }}';
                form.method = 'POST';
                form.style.display = 'none';

                addHiddenInput(form, '_token', '{{ csrf_token() }}');
                addHiddenInput(form, 'format', format);
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

                setTimeout(() => {
                    btn.innerHTML = originalText;
                    // Buttons state will be refreshed by updateSelectedCount when modal is shown next time
                }, 2000);

            } catch (error) {
                console.error('Export error:', error.message);
                alert('An error occurred during export. Please try again. Details: ' + error.message);
                const btn = format === 'csv' ? csvBtn : pdfBtn;
                if (format === 'csv') {
                    btn.innerHTML = '<i class="bi bi-download me-1"></i> Export CSV';
                } else {
                    btn.innerHTML = '<i class="bi bi-download me-1"></i> Export PDF';
                }
                updateSelectedCount();
            }
        }

        csvBtn.addEventListener('click', () => handleDownload('csv'));
        pdfBtn.addEventListener('click', () => handleDownload('pdf'));
    });
</script>
