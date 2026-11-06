{{-- Change history of the employee profile (App\View\Components\EmployeeChangeHistory, only for `view-audit-log`) --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-0 text-body-emphasis">
                        <i class="bi bi-journal-text me-2 text-primary"></i>{{ __('Change history') }}
                    </h5>
                    <p class="mb-0 text-muted small">{{ __('Changes made in this application (audit log), newest first') }}</p>
                </div>
                @if ($entries->isNotEmpty())
                    <a href="{{ route('audit-log.index', ['subject_type' => 'employee', 'subject_id' => $employee->emp_no]) }}"
                        class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-box-arrow-up-right me-1"></i>{{ $hasMore ? __('Show all') : __('Open in audit log') }}
                    </a>
                @endif
            </div>
            @if ($entries->isEmpty())
                <div class="card-body text-center text-muted py-4">
                    <i class="bi bi-journal display-6 d-block mb-2"></i>
                    {{ __('No changes recorded yet.') }}
                </div>
            @else
                <ul class="list-group list-group-flush">
                    @foreach ($entries as $entry)
                        <li class="list-group-item">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                                <span class="badge role-badge {{ $presenter::actionClass($entry->action) }}">{{ $presenter::actionLabel($entry->action) }}</span>
                                <small class="text-body-secondary">
                                    <i class="bi bi-clock me-1"></i>{{ $entry->created_at->format('d.m.Y H:i') }}
                                    <i class="bi bi-person ms-2 me-1"></i>{{ $entry->user?->name ?? __('System') }}
                                </small>
                            </div>
                            @include('audit-log.partials.changes', ['changes' => $entry->changes, 'presenter' => $presenter])
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
