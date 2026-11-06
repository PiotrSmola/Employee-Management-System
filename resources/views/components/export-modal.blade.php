<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true"
    data-export-url="{{ route('employees.export') }}" data-csrf-token="{{ csrf_token() }}">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exportModalLabel">
                    <i class="bi bi-download me-2 text-primary"></i>
                    Export Selected Employee Data
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <span id="selectedCountText">Loading selected employees...</span>
                </div>

                <div class="row g-3">
                    @foreach ($exportFormats as $format => $option)
                        <div class="col-md-4">
                            <div class="card h-100 export-option" data-format="{{ $format }}">
                                <div class="card-body text-center">
                                    <i class="bi {{ $option['icon'] }} display-4 text-{{ $option['color'] }} mb-3"></i>
                                    <h6 class="card-title">{{ $option['label'] }}</h6>
                                    <button type="button" class="btn btn-{{ $option['color'] }} mt-2 export-format-btn"
                                        data-format="{{ $format }}" data-label="{{ __('Export') }} {{ $option['button'] }}"
                                        data-queue-above="{{ $option['queueAbove'] }}">
                                        <i class="bi bi-download me-1"></i> {{ __('Export') }} {{ $option['button'] }}
                                    </button>
                                    <div class="small text-body-secondary mt-2 export-queue-hint d-none">
                                        <i class="bi bi-hourglass-split me-1"></i>{{ __('Generated in the background (My exports)') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 text-body-emphasis"><i class="bi bi-layout-three-columns me-1"></i>{{ __('Columns') }}</h6>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" id="exportColumnsAll">{{ __('All') }}</button>
                            <button type="button" class="btn btn-outline-secondary" id="exportColumnsNone">{{ __('None') }}</button>
                        </div>
                    </div>
                    <div class="row g-2" id="exportColumns">
                        @foreach ($exportColumns as $key => $label)
                            <div class="col-6 col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input export-column" type="checkbox" value="{{ $key }}"
                                        id="exportColumn-{{ $key }}" checked>
                                    <label class="form-check-label" for="exportColumn-{{ $key }}">{{ $label }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="form-text">
                        {{ __('The chosen columns apply to every format. PDFs with more than :pdf rows and CSV / Excel files with more than :rows rows are generated in the background and listed under My exports.', [
                            'pdf' => $exportPdfLimit,
                            'rows' => $exportQueueThreshold,
                        ]) }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('js/export-modal.js') }}"></script>
