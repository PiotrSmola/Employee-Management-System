{{-- One summary figure: $gradient (1-4), $icon, $value, $label --}}
<div class="col-6 col-lg-3">
    <div class="card gradient-card-{{ $gradient }} text-white h-100 report-tile">
        <div class="card-body text-center">
            <i class="bi bi-{{ $icon }} fs-2 opacity-75"></i>
            <div class="fs-3 fw-bold mt-1">{{ $value }}</div>
            <div class="small opacity-90">{{ $label }}</div>
        </div>
    </div>
</div>
