<div class="card mb-4" id="department-management">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        @foreach ($departments as $row)
            <div class="d-flex align-items-center me-lg-4">
                <i class="bi bi-person-badge fs-3 text-primary me-2"></i>
                <div>
                    <div class="small text-muted">
                        {{ __('Department manager') }}
                        @if ($row->linked)
                            (<a href="{{ route('departments.show', $row->dept_no) }}">{{ $row->dept_name }}</a>)
                        @else
                            ({{ $row->dept_name }})
                        @endif
                    </div>
                    @if (! $row->hasManager)
                        <span class="fst-italic text-muted">{{ __('none') }}</span>
                    @elseif ($row->isThisEmployee)
                        <span class="fw-semibold">{{ __('this employee') }}</span>
                    @elseif ($row->managerName)
                        <a href="{{ route('employees.show', $row->managerEmpNo) }}" class="fw-semibold">{{ $row->managerName }}</a>
                    @else
                        <span class="text-muted">&ndash;</span>
                    @endif
                </div>
            </div>
        @endforeach

        @if ($periods->isNotEmpty())
            <div class="d-flex align-items-center flex-wrap gap-2">
                <span class="badge role-badge {{ $managesNow ? 'text-bg-warning' : 'text-bg-secondary' }}">
                    <i class="bi bi-award me-1"></i>
                    {{ $managesNow ? __('Department manager') : __('Former department manager') }}
                </span>
                @foreach ($periods as $period)
                    <small class="text-muted">
                        @if ($period->linked)
                            <a href="{{ route('departments.show', $period->dept_no) }}">{{ $period->dept_name }}</a>:
                        @else
                            {{ $period->dept_name }}:
                        @endif
                        {{ $period->from }} &ndash; {{ $period->to }}{{ $loop->last ? '' : ';' }}
                    </small>
                @endforeach
            </div>
        @endif
    </div>
</div>
