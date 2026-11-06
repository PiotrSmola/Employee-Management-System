{{-- Assign / end the department manager (manage-departments). Errors come in the bags of DepartmentManagementService. --}}
@php
    $assignErrors = $errors->getBag(App\Services\DepartmentManagementService::ASSIGN_BAG);
    $endErrors = $errors->getBag(App\Services\DepartmentManagementService::END_BAG);
@endphp
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-person-plus me-2"></i>{{ __('Assign a manager') }}</h5>
    </div>
    <div class="card-body">
        <p class="small text-body-secondary">
            {{ __('Choose a current employee of the department. The current manager\'s period ends on the effective date.') }}
        </p>
        <form method="POST" action="{{ route('departments.managers.store', $department) }}" id="assignManagerForm" novalidate>
            @csrf
            <div class="mb-3 position-relative">
                <label for="managerSearch" class="form-label fw-semibold">{{ __('Employee') }}</label>
                <input type="hidden" name="emp_no" id="managerEmpNo" value="{{ old('emp_no') }}">
                <input type="hidden" name="emp_label" id="managerLabel" value="{{ old('emp_label') }}">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="managerSearch" autocomplete="off"
                        class="form-control @if ($assignErrors->has('emp_no')) is-invalid @endif"
                        placeholder="{{ __('Name or employee number...') }}"
                        value="{{ old('emp_label') }}"
                        data-url="{{ route('departments.managers.candidates', $department) }}"
                        aria-describedby="managerSearchHelp">
                    @if ($assignErrors->has('emp_no'))
                        <div class="invalid-feedback">{{ $assignErrors->first('emp_no') }}</div>
                    @endif
                </div>
                <div id="managerResults" class="list-group position-absolute w-100 shadow d-none"
                    style="z-index: 1050; max-height: 280px; overflow-y: auto;"></div>
                <div id="managerSearchHelp" class="form-text">{{ __('Type to search; pick an employee from the list.') }}</div>
            </div>
            <div class="mb-3">
                <label for="from_date" class="form-label fw-semibold">{{ __('Effective date') }}</label>
                <input type="date" id="from_date" name="from_date" value="{{ old('from_date') }}" max="{{ now()->toDateString() }}"
                    @if ($currentManager) min="{{ \Carbon\Carbon::parse($currentManager->from_date)->addDay()->toDateString() }}" @endif
                    class="form-control @if ($assignErrors->has('from_date')) is-invalid @endif" required>
                @if ($assignErrors->has('from_date'))
                    <div class="invalid-feedback">{{ $assignErrors->first('from_date') }}</div>
                @endif
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>{{ __('Assign manager') }}
            </button>
        </form>
    </div>
</div>

@if ($currentManager)
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0 text-body-emphasis"><i class="bi bi-person-dash me-2"></i>{{ __('End management') }}</h5>
        </div>
        <div class="card-body">
            <p class="small text-body-secondary">
                {{ __('Ends the period of :name without a successor; the department then has no manager.', ['name' => $currentManager->first_name . ' ' . $currentManager->last_name]) }}
            </p>
            <form method="POST" action="{{ route('departments.managers.end', $department) }}" novalidate
                onsubmit="return confirm(@js(__('End the management of :name?', ['name' => $currentManager->first_name . ' ' . $currentManager->last_name])))">
                @csrf
                <div class="mb-3">
                    <label for="to_date" class="form-label fw-semibold">{{ __('End date') }}</label>
                    <input type="date" id="to_date" name="to_date" value="{{ old('to_date') }}" max="{{ now()->toDateString() }}"
                        min="{{ \Carbon\Carbon::parse($currentManager->from_date)->addDay()->toDateString() }}"
                        class="form-control @if ($endErrors->has('to_date')) is-invalid @endif" required>
                    @if ($endErrors->has('to_date'))
                        <div class="invalid-feedback">{{ $endErrors->first('to_date') }}</div>
                    @endif
                </div>
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-person-dash me-1"></i>{{ __('End management') }}
                </button>
            </form>
        </div>
    </div>
@endif

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const search = document.getElementById('managerSearch');
        const results = document.getElementById('managerResults');
        const empNo = document.getElementById('managerEmpNo');
        const label = document.getElementById('managerLabel');
        const form = document.getElementById('assignManagerForm');
        const noResults = @json(__('No current employee found'));
        const chooseFirst = @json(__('Choose an employee from the list.'));
        const sinceLabel = @json(__('in the department since'));
        let timer = null;
        let request = 0;

        const hide = () => results.classList.add('d-none');

        const render = (rows) => {
            results.replaceChildren();
            if (rows.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-muted small';
                empty.textContent = noResults;
                results.append(empty);
            }
            rows.forEach(row => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action';
                const name = document.createElement('div');
                name.className = 'fw-semibold';
                name.textContent = row.name + ' (#' + row.emp_no + ')';
                const meta = document.createElement('small');
                meta.className = 'text-muted';
                meta.textContent = [row.title, row.since ? sinceLabel + ' ' + row.since : null].filter(Boolean).join(' · ');
                item.append(name, meta);
                item.addEventListener('click', () => {
                    empNo.value = row.emp_no;
                    label.value = row.name + ' (#' + row.emp_no + ')';
                    search.value = label.value;
                    search.classList.remove('is-invalid');
                    hide();
                });
                results.append(item);
            });
            results.classList.remove('d-none');
        };

        const load = () => {
            const current = ++request;
            fetch(search.dataset.url + '?q=' + encodeURIComponent(search.value.trim()), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(response => response.ok ? response.json() : { data: [] })
                .then(json => { if (current === request) render(json.data || []); })
                .catch(hide);
        };

        search.addEventListener('input', () => {
            empNo.value = '';
            label.value = '';
            clearTimeout(timer);
            timer = setTimeout(load, 250);
        });
        search.addEventListener('focus', () => { if (empNo.value === '') load(); });
        search.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
        document.addEventListener('click', event => {
            if (!results.contains(event.target) && event.target !== search) hide();
        });
        form.addEventListener('submit', event => {
            if (empNo.value === '') {
                event.preventDefault();
                search.classList.add('is-invalid');
                search.setCustomValidity(chooseFirst);
                search.reportValidity();
                search.setCustomValidity('');
            }
        });
    });
</script>
