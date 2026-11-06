{{-- Validation errors of one HR action form ($bag = its error bag) --}}
@if ($errors->getBag($bag)->any())
    <div class="alert alert-danger small" role="alert">
        <i class="bi bi-exclamation-triangle me-1"></i>
        @if ($errors->getBag($bag)->count() === 1)
            {{ $errors->getBag($bag)->first() }}
        @else
            <ul class="mb-0 ps-3">
                @foreach ($errors->getBag($bag)->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
