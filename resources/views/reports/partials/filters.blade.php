{{-- Department (not for managers) and as-of date. GET form: every state of the page is a link. --}}
@php
    $presets = [null => __('Current')];
    foreach ([2000, 1995, 1990] as $year) {
        $presets[$year . '-12-31'] = __('End of :year', ['year' => $year]);
    }
@endphp
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ url()->current() }}" class="row g-3 align-items-end">
            @unless ($context->isDepartmentScoped())
                <div class="col-sm-6 col-lg-4">
                    <label for="report-department" class="form-label fw-semibold">{{ __('Department') }}</label>
                    <select id="report-department" name="department" class="form-select">
                        <option value="">{{ __('All departments') }}</option>
                        @foreach ($departments as $deptNo => $name)
                            <option value="{{ $deptNo }}" @selected($context->department === $deptNo)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
            <div class="col-sm-6 col-lg-3">
                <label for="report-as-of" class="form-label fw-semibold">{{ __('As of date') }}</label>
                <input type="date" id="report-as-of" name="as_of" class="form-control"
                    value="{{ $context->current ? '' : $context->date() }}" max="{{ now()->toDateString() }}"
                    min="{{ \App\Reports\ReportContext::EARLIEST_YEAR }}-01-01">
            </div>
            <div class="col-12 col-lg-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel me-1"></i>{{ __('Show') }}
                </button>
            </div>
            <div class="col-12 col-lg">
                <div class="d-flex flex-wrap gap-2 justify-content-lg-end" role="group" aria-label="{{ __('Quick dates') }}">
                    @foreach ($presets as $date => $label)
                        @php $active = $date === '' ? $context->current : (! $context->current && $context->date() === $date); @endphp
                        <a href="{{ url()->current() . '?' . http_build_query(array_filter(['department' => $context->isDepartmentScoped() ? null : $context->department, 'as_of' => $date ?: null])) }}"
                            class="btn btn-sm {{ $active ? 'btn-secondary' : 'btn-outline-secondary' }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
        </form>
        <div class="small text-body-secondary mt-2">
            <i class="bi bi-calendar3 me-1"></i>
            @if ($context->current)
                {{ __('Current state (:date). Histories run up to today.', ['date' => $context->asOf->format('d.m.Y')]) }}
            @else
                {{ __('State of the company on :date. Histories run up to that day.', ['date' => $context->asOf->format('d.m.Y')]) }}
            @endif
        </div>
    </div>
</div>
