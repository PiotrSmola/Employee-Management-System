<div class="card mb-4" wire:id="{{ $this->getId() }}">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-body-emphasis">
                <i class="bi bi-search me-2"></i>
                Search & Filter Employees
            </h5>
            <button class="btn btn-outline-secondary btn-sm" wire:click="$toggle('showFilters')" type="button">
                <i class="bi bi-funnel"></i>
                <span class="d-none d-sm-inline ms-1 text-body-emphasis">
                    {{ $showFilters ? 'Hide' : 'Show' }} Filters
                </span>
            </button>
        </div>
    </div>

    <div class="card-body">
        <div class="row mb-3">
            <div class="col-12">
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" class="form-control form-control-lg"
                        placeholder="Search by first name, last name, or full name..."
                        wire:model.live.debounce.300ms="search">
                    @if ($search)
                        <button class="btn btn-outline-secondary" type="button" wire:click="$set('search', '')">
                            <i class="bi bi-x"></i>
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <div class="collapse {{ $showFilters ? 'show' : '' }}">
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <label class="form-label fw-semibold text-body-emphasis">Employment Status</label>
                    <select class="form-select" wire:model.live="employmentStatus">
                        <option value="all">All Employees</option>
                        <option value="current">Current Employees</option>
                        <option value="former">Former Employees</option>
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label class="form-label fw-semibold text-body-emphasis">Gender</label>
                    <select class="form-select" wire:model.live="gender">
                        <option value="all">All Genders</option>
                        <option value="M">Male</option>
                        <option value="F">Female</option>
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label class="form-label fw-semibold text-body-emphasis">Department</label>
                    <select class="form-select" wire:model.live="department">
                        <option value="all">All Departments</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->dept_no }}">{{ $dept->dept_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label class="form-label fw-semibold text-body-emphasis">Job Title</label>
                    <select class="form-select" wire:model.live="jobTitle">
                        <option value="all">All Job Titles</option>
                        @foreach ($jobTitles as $title)
                            <option value="{{ $title->title }}">{{ $title->title }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label class="form-label fw-semibold text-body-emphasis">Salary Range</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <input type="number" class="form-control form-control-sm" placeholder="Min"
                                wire:model.live.debounce.500ms="salaryMin">
                        </div>
                        <div class="col-6">
                            <input type="number" class="form-control form-control-sm" placeholder="Max"
                                wire:model.live.debounce.500ms="salaryMax">
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-12">
                    <button class="btn btn-outline-danger btn-sm" wire:click="resetAllFilters">
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        <span class="text-body-emphasis">Reset All Filters</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
