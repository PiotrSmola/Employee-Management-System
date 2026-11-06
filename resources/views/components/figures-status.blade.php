{{-- How current the cached figures are (App\Support\FigureCache). --}}
@if ($preparing)
    <div class="alert alert-info d-flex align-items-center" role="status" id="figures-preparing">
        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
        {{ __('The figures are being prepared. This page reloads by itself.') }}
    </div>
    <script src="{{ asset('js/auto-reload.js') }}" data-delay="5000"></script>
@elseif ($stale)
    <p class="small text-muted mb-3" id="figures-stale">
        <i class="bi bi-arrow-repeat me-1"></i>
        {{ __('These figures are being refreshed and may be slightly out of date.') }}
    </p>
@endif
