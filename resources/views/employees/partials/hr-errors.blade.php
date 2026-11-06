{{-- Validation errors of one HR action form ($bag = its error bag) --}}
@php($bagErrors = $errors->getBag($bag))
@if ($bagErrors->any())
    <div class="alert alert-danger small" role="alert">
        <i class="bi bi-exclamation-triangle me-1"></i>
        @if ($bagErrors->count() === 1)
            {{ $bagErrors->first() }}
        @else
            <ul class="mb-0 ps-3">
                @foreach ($bagErrors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
