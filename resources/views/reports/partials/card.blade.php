{{-- A report: charts, the table (collapsible) and the CSV download. $table: App\Reports\ReportTable --}}
@php
    $tableId = 'report-table-' . $table->key;
    $format = function (mixed $value, string $type): string {
        if ($value === null || $value === '') {
            return '–';
        }

        return match ($type) {
            'int' => number_format((float) $value),
            'money' => '$' . number_format((float) $value),
            'percent' => number_format((float) $value, 1) . '%',
            'years' => number_format((float) $value, 1),
            'date' => \Carbon\Carbon::parse($value)->format('d.m.Y'),
            default => (string) $value,
        };
    };
    $chartCount = count($table->charts);
@endphp
<div class="card h-100 report-card" id="report-{{ $table->key }}">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h5 class="mb-0 text-body-emphasis">{{ $table->title }}</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse"
                data-bs-target="#{{ $tableId }}" aria-expanded="{{ $chartCount === 0 ? 'true' : 'false' }}" aria-controls="{{ $tableId }}">
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
            @if ($chartCount > 0)
                <div class="row g-3">
                    @foreach ($table->charts as $chart)
                        @php
                            $labels = count($chart['labels']);
                            $height = match (true) {
                                $chart['type'] === 'doughnut' => 260,
                                ! empty($chart['horizontal']) => max(200, $labels * 28 + 70),
                                default => 300,
                            };
                            $col = match (true) {
                                $chartCount === 1 => 'col-12',
                                $chart['type'] === 'doughnut' => 'col-lg-4',
                                $chartCount === 3 => 'col-lg-4',
                                default => 'col-lg-6',
                            };
                        @endphp
                        <div class="{{ $col }}">
                            @if (! empty($chart['title']))
                                <h6 class="small fw-semibold text-body-secondary text-center mb-2">{{ $chart['title'] }}</h6>
                            @endif
                            <div class="report-chart" style="height: {{ $height }}px;">
                                <canvas id="{{ $chart['id'] }}" data-chart="{{ json_encode($chart) }}" role="img"
                                    aria-label="{{ $table->title }}{{ ! empty($chart['title']) ? ' - ' . $chart['title'] : '' }}"></canvas>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="collapse {{ $chartCount === 0 ? 'show' : '' }} mt-3" id="{{ $tableId }}">
                <div class="table-responsive report-table">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                @foreach ($table->columns as [$label, $type])
                                    <th scope="col" class="{{ $type === 'text' || $type === 'date' ? '' : 'text-end' }} text-nowrap">{{ $label }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($table->rows as $row)
                                <tr>
                                    @foreach ($table->columns as $key => [, $type])
                                        <td class="{{ $type === 'text' || $type === 'date' ? '' : 'text-end' }} text-nowrap">{{ $format($row[$key] ?? null, $type) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($table->totals !== [])
                            <tfoot>
                                @foreach ($table->totals as $row)
                                    <tr class="fw-semibold">
                                        @foreach ($table->columns as $key => [, $type])
                                            <td class="{{ $type === 'text' || $type === 'date' ? '' : 'text-end' }} text-nowrap">{{ $format($row[$key] ?? null, $type) }}</td>
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
