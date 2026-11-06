{{-- Assign / end the department manager (manage-departments). Error bags: DepartmentManagementService::ASSIGN_BAG, END_BAG. --}}
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-person-plus me-2"></i>{{ __('Assign a manager') }}</h5>
    </div>
    <div class="card-body">
        <p class="small text-body-secondary">
            {{ __('Choose a current employee of the department. The current manager\'s period ends on the effective date.') }}
        </p>
        <form method="POST" action="{{ route('departments.managers.store', $department) }}" id="assignManagerForm" novalidate
            data-no-results="{{ __('No current employee found') }}"
            data-choose-first="{{ __('Choose an employee from the list.') }}"
            data-since-label="{{ __('in the department since') }}"
            data-role-current="{{ __('Current manager') }}"
            data-role-former="{{ __('Former manager of this department') }}">
            @csrf
            <div class="mb-3 position-relative">
                <label for="managerSearch" class="form-label fw-semibold">{{ __('Employee') }}</label>
                <input type="hidden" name="emp_no" id="managerEmpNo" value="{{ old('emp_no') }}">
                <input type="hidden" name="emp_label" id="managerLabel" value="{{ old('emp_label') }}">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="managerSearch" autocomplete="off"
                        class="form-control @error('emp_no', 'assignManager') is-invalid @enderror"
                        placeholder="{{ __('Name or employee number...') }}"
                        value="{{ old('emp_label') }}"
                        data-url="{{ route('departments.managers.candidates', $department) }}"
                        aria-describedby="managerSearchHelp">
                    @error('emp_no', 'assignManager')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div id="managerResults" class="list-group position-absolute w-100 shadow d-none"
                    style="z-index: 1050; max-height: 280px; overflow-y: auto;"></div>
                <div id="managerSearchHelp" class="form-text">{{ __('Type a number or at least two letters of a name; pick an employee from the list.') }}</div>
            </div>
            <div class="mb-3">
                <label for="from_date" class="form-label fw-semibold">{{ __('Effective date') }}</label>
                <input type="date" id="from_date" name="from_date" value="{{ old('from_date') }}" max="{{ $today }}"
                    min="{{ $earliestManagerChange }}"
                    class="form-control @error('from_date', 'assignManager') is-invalid @enderror" required>
                @error('from_date', 'assignManager')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
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
                {{ __('Ends the period of :name without a successor; the department then has no manager.', ['name' => $currentManager->name]) }}
            </p>
            <form method="POST" action="{{ route('departments.managers.end', $department) }}" novalidate
                onsubmit="return confirm(@js(__('End the management of :name?', ['name' => $currentManager->name])))">
                @csrf
                <div class="mb-3">
                    <label for="to_date" class="form-label fw-semibold">{{ __('End date') }}</label>
                    <input type="date" id="to_date" name="to_date" value="{{ old('to_date') }}" max="{{ $today }}"
                        min="{{ $earliestManagerChange }}"
                        class="form-control @error('to_date', 'endManagement') is-invalid @enderror" required>
                    @error('to_date', 'endManagement')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-person-dash me-1"></i>{{ __('End management') }}
                </button>
            </form>
        </div>
    </div>
@endif

<script src="{{ asset('js/department-manager-select.js') }}"></script>
