{{-- A report: charts, the table (collapsible) and the CSV download. $table: App\Reports\ReportTable --}}
<div class="card h-100 report-card" id="report-{{ $table->key }}">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h5 class="mb-0 text-body-emphasis">{{ $table->title }}</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse"
                data-bs-target="#{{ $table->tableId() }}" aria-expanded="{{ $table->showsTableFirst() ? 'true' : 'false' }}" aria-controls="{{ $table->tableId() }}">
                <i class="bi bi-table me-1"></i>{{ __('Table') }}
            </button>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('reports.csv', ['report' => $table->key, ...$context->query()]) }}">
                <i class="bi bi-filetype-csv me-1"></i>{{ __('CSV') }}
            </a>
        </div>
    </div>
    <div class="card-body">
        @if ($table->description)
            <p class="small text-body-secondary">{{ $table->description }}</p>
        @endif

        @if ($table->isEmpty())
            <p class="text-body-secondary text-center my-4">{{ __('No data for this selection.') }}</p>
        @else
            @unless ($table->showsTableFirst())
                <div class="row g-3">
                    @foreach ($table->chartCards() as $chart)
                        <div class="{{ $chart['column'] }}">
                            @if ($chart['title'])
                                <h6 class="small fw-semibold text-body-secondary text-center mb-2">{{ $chart['title'] }}</h6>
                            @endif
                            <div class="report-chart" style="height: {{ $chart['height'] }}px;">
                                <canvas id="{{ $chart['id'] }}" data-chart="{{ $chart['json'] }}" role="img" aria-label="{{ $chart['label'] }}"></canvas>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endunless

            <div class="collapse {{ $table->showsTableFirst() ? 'show' : '' }} mt-3" id="{{ $table->tableId() }}">
                <div class="table-responsive report-table">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                @foreach ($table->columns as [$label, $type])
                                    <th scope="col" class="{{ $table::cellClass($type) }}">{{ $label }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($table->rows as $row)
                                <tr>
                                    @foreach ($table->columns as $key => [, $type])
                                        <td class="{{ $table::cellClass($type) }}">{{ $table::cell($row[$key] ?? null, $type) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($table->totals !== [])
                            <tfoot>
                                @foreach ($table->totals as $row)
                                    <tr class="fw-semibold">
                                        @foreach ($table->columns as $key => [, $type])
                                            <td class="{{ $table::cellClass($type) }}">{{ $table::cell($row[$key] ?? null, $type) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
