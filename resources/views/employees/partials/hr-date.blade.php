{{-- Effective / end date of an HR action: $bag, $field, $label, $min (earliest allowed date or null); max = today --}}
<label for="hr_{{ $bag }}_{{ $field }}" class="form-label fw-semibold">{{ $label }}</label>
<input type="date" id="hr_{{ $bag }}_{{ $field }}" name="{{ $field }}"
    class="form-control {{ $errors->getBag($bag)->has($field) ? 'is-invalid' : '' }}"
    value="{{ $errors->getBag($bag)->any() ? old($field, $today) : $today }}"
    @if ($min) min="{{ $min }}" @endif max="{{ $today }}" required @disabled(! $possible($min))>
<div class="form-text">
    @if (! $possible($min))
        <span class="text-danger">{{ __('Not possible yet: the current record started today. Try again tomorrow, or use a correction.') }}</span>
    @elseif ($min)
        {{ __('From :min to today. Dates in the future are not accepted.', ['min' => \App\Support\EmploymentRules::display($min)]) }}
    @else
        {{ __('Today or earlier. Dates in the future are not accepted.') }}
    @endif
</div>
