<div wire:id="{{ $this->getId() }}" class="employee-list-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-people me-2 text-primary"></i>
            Employee Directory
        </h1>
        <div class="d-flex align-items-center gap-3">
            <div class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                {{ $employees->total() }} employee(s) found
            </div>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#exportModal"
                data-selected-count="{{ $this->getSelectedCount() }}" data-total-count="{{ $employees->total() }}"
                id="exportTriggerBtn">
                <i class="bi bi-download me-1"></i>
                Export Selected (<span id="exportCountDisplay">{{ $this->getSelectedCount() }}</span>)
            </button>
        </div>
    </div>

    <!-- Selection Controls -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex align-items-center justify-content-between">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" wire:model.live="selectAll" id="selectAllCheckbox">
                    <label class="form-check-label fw-semibold" for="selectAllCheckbox">
                        Select all ({{ $employees->total() }} records)
                    </label>
                </div>
                <div>
                    <span class="text-muted small">
                        Selected {{ $this->getSelectedCount() }} of {{ $employees->total() }} records
                    </span>
                    @if ($this->getSelectedCount() !== $employees->total())
                        <button class="btn btn-sm btn-outline-secondary ms-2" wire:click="resetSelection">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div wire:loading class="text-center py-3">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Employee Table -->
    <div class="card shadow-sm table-card" wire:loading.class="opacity-50">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th scope="col" style="width: 50px;">
                                <i class="bi bi-check-square"></i>
                            </th>
                            <th scope="col" wire:click="sortByField('emp_no')" class="sortable-header">
                                Employee #
                                @if ($sortBy === 'emp_no')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ms-1"></i>
                                @else
                                    <i class="bi bi-arrow-up-down ms-1 opacity-50"></i>
                                @endif
                            </th>
                            <th scope="col" wire:click="sortByField('name')" class="sortable-header">
                                Name
                                @if ($sortBy === 'name')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ms-1"></i>
                                @else
                                    <i class="bi bi-arrow-up-down ms-1 opacity-50"></i>
                                @endif
                            </th>
                            <th scope="col" wire:click="sortByField('department')" class="sortable-header">
                                Department
                                @if ($sortBy === 'department')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ms-1"></i>
                                @else
                                    <i class="bi bi-arrow-up-down ms-1 opacity-50"></i>
                                @endif
                            </th>
                            <th scope="col" wire:click="sortByField('title')" class="sortable-header">
                                Job Title
                                @if ($sortBy === 'title')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ms-1"></i>
                                @else
                                    <i class="bi bi-arrow-up-down ms-1 opacity-50"></i>
                                @endif
                            </th>
                            <th scope="col" wire:click="sortByField('salary')" class="sortable-header">
                                Current Salary
                                @if ($sortBy === 'salary')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ms-1"></i>
                                @else
                                    <i class="bi bi-arrow-up-down ms-1 opacity-50"></i>
                                @endif
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($employees as $employee)
                            <tr class="employee-row">
                                <td>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                            {{ $employee->isSelected ? 'checked' : '' }}
                                            wire:click="toggleEmployee({{ $employee->emp_no }})"
                                            onclick="event.stopPropagation()">
                                    </div>
                                </td>
                                <td class="emp-number">{{ $employee->emp_no }}</td>
                                <td style="cursor: pointer;"
                                    onclick="window.location='{{ route('employees.show', $employee->emp_no) }}'">
                                    <div class="fw-semibold">
                                        <a href="{{ route('employees.show', $employee->emp_no) }}"
                                            class="text-decoration-none text-reset">
                                            {{ $employee->first_name }} {{ $employee->last_name }}
                                        </a>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        Hired: {{ \Carbon\Carbon::parse($employee->hire_date)->format('M d, Y') }}
                                        <span
                                            class="ms-2 text-white rounded px-1 {{ $employee->gender === 'M' ? 'bg-primary' : 'bg-pink' }}">
                                            {{ $employee->gender === 'M' ? 'Male' : 'Female' }}
                                        </span>
                                    </small>
                                </td>
                                <td style="cursor: pointer;"
                                    onclick="window.location='{{ route('employees.show', $employee->emp_no) }}'">
                                    @if ($employee->current_dept_name)
                                        <span class="badge bg-department">
                                            {{ $employee->current_dept_name }}
                                        </span>
                                    @elseif($employee->last_dept_name)
                                        <span class="badge bg-secondary">
                                            Former Employee - {{ $employee->last_dept_name }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary">N/A</span>
                                    @endif
                                </td>
                                <td style="cursor: pointer;"
                                    onclick="window.location='{{ route('employees.show', $employee->emp_no) }}'">
                                    @if ($employee->current_title)
                                        <span class="fw-medium text-dark">{{ $employee->current_title }}</span>
                                    @elseif($employee->last_job_title)
                                        <span class="fw-medium text-muted">{{ $employee->last_job_title }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td style="cursor: pointer;"
                                    onclick="window.location='{{ route('employees.show', $employee->emp_no) }}'">
                                    @if ($employee->currentSalary)
                                        <span class="badge bg-salary">
                                            ${{ number_format($employee->currentSalary->salary) }}
                                        </span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="bi bi-search display-4 d-block mb-2"></i>
                                    <p class="mb-0">No employees found matching your criteria</p>
                                    <button class="btn btn-sm btn-outline-primary mt-2"
                                        wire:click="$dispatch('resetFilters')">
                                        Clear Filters
                                    </button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($employees->hasPages())
            <div class="card-footer">
                <div class="row align-items-center">
                    <div class="col-sm-6">
                        <small class="text-muted">
                            Showing {{ $employees->firstItem() }}-{{ $employees->lastItem() }}
                            of {{ number_format($employees->total()) }}
                        </small>
                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex justify-content-sm-end justify-content-center">
                            {{ $employees->links('livewire.custom-pagination') }}
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
