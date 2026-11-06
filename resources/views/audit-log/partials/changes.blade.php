{{-- The `changes` of one audit entry as "field: before -> after" ($presenter: App\Support\AuditLogPresenter) --}}
@php($rows = $presenter->rows($changes))
@if ($rows === [])
    <span class="text-body-secondary small">-</span>
@else
    <dl class="audit-changes small mb-0">
        @foreach ($rows as $row)
            <div class="d-flex flex-wrap column-gap-1">
                <dt class="fw-semibold text-body-emphasis">{{ $row['field'] }}:</dt>
                <dd class="mb-0 text-break">
                    @if ($row['change'] && $row['before'] !== null)
                        <span class="text-body-secondary">{{ $row['before'] }}</span>
                        <i class="bi bi-arrow-right mx-1 text-primary" aria-label="{{ __('changed to') }}"></i>
                        <span class="fw-semibold">{{ $row['after'] ?? '-' }}</span>
                    @else
                        {{ $row['after'] ?? '-' }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
