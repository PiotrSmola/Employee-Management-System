@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Audit log') . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('index') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">{{ __('Audit log') }}</li>
            </ol>
        </nav>

        <h1 class="h3 mb-4">
            <i class="bi bi-journal-text me-2 text-primary"></i>{{ __('Audit log') }}
        </h1>

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="{{ route('audit-log.index') }}" class="row g-2 align-items-end">
                    <div class="col-md-6 col-lg-3">
                        <label for="filter_action" class="form-label fw-semibold text-body-emphasis">{{ __('Action') }}</label>
                        <select id="filter_action" name="action" class="form-select">
                            <option value="">{{ __('All actions') }}</option>
                            @foreach ($actionGroups as $group => $groupActions)
                                <optgroup label="{{ $presenter::subjectLabel($group) }}">
                                    <option value="{{ $group }}.*" @selected(($filters['action'] ?? null) === $group . '.*')>
                                        {{ __('All :group actions', ['group' => $group]) }}
                                    </option>
                                    @foreach ($groupActions as $action)
                                        <option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>
                                            {{ $presenter::actionLabel($action) }} ({{ $action }})
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="filter_subject_type" class="form-label fw-semibold text-body-emphasis">{{ __('Subject') }}</label>
                        <select id="filter_subject_type" name="subject_type" class="form-select">
                            <option value="">{{ __('Any') }}</option>
                            @foreach ($subjectTypes as $type)
                                <option value="{{ $type }}" @selected(($filters['subject_type'] ?? null) === $type)>{{ $presenter::subjectLabel($type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="filter_subject_id" class="form-label fw-semibold text-body-emphasis">{{ __('Subject ID') }}</label>
                        <input type="text" id="filter_subject_id" name="subject_id" value="{{ $filters['subject_id'] ?? '' }}"
                            class="form-control" placeholder="{{ __('e.g. 10001') }}" maxlength="64">
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label for="filter_user" class="form-label fw-semibold text-body-emphasis">{{ __('User') }}</label>
                        <select id="filter_user" name="user" class="form-select">
                            <option value="">{{ __('Anyone') }}</option>
                            <option value="{{ $noUser }}" @selected(($filters['user'] ?? null) === $noUser)>
                                {{ __('No user (system, failed sign-ins)') }}
                            </option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected(($filters['user'] ?? null) === (string) $user->id)>{{ $user->name }} ({{ $user->email }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="filter_from" class="form-label fw-semibold text-body-emphasis">{{ __('From') }}</label>
                        <input type="date" id="filter_from" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="filter_to" class="form-label fw-semibold text-body-emphasis">{{ __('To') }}</label>
                        <input type="date" id="filter_to" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-md-6 col-lg-3 d-flex gap-2 ms-auto">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-funnel me-1"></i>{{ __('Filter') }}
                        </button>
                        @if ($filters !== [])
                            <a href="{{ route('audit-log.index') }}" class="btn btn-outline-secondary" title="{{ __('Reset') }}">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm table-card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-primary">
                            <tr>
                                <th scope="col" style="width: 12%;">{{ __('When') }}</th>
                                <th scope="col" style="width: 15%;">{{ __('User') }}</th>
                                <th scope="col" style="width: 15%;">{{ __('Action') }}</th>
                                <th scope="col" style="width: 18%;">{{ __('Subject') }}</th>
                                <th scope="col">{{ __('Changes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $entry)
                                <tr>
                                    <td class="small text-nowrap">
                                        {{ $entry->created_at->format('d.m.Y') }}<br>
                                        <span class="text-body-secondary">{{ $entry->created_at->format('H:i:s') }}</span>
                                    </td>
                                    <td class="small">
                                        @if ($entry->user)
                                            <div class="fw-semibold">{{ $entry->user->name }}</div>
                                            <div class="text-body-secondary text-break">{{ $entry->user->email }}</div>
                                        @elseif ($entry->user_id)
                                            <span class="text-body-secondary">{{ __('Deleted user') }}</span>
                                        @else
                                            <span class="text-body-secondary">{{ __('System') }}</span>
                                        @endif
                                        @if ($entry->ip)
                                            <div class="text-body-secondary">{{ $entry->ip }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge role-badge {{ $presenter::actionClass($entry->action) }}">{{ $presenter::actionLabel($entry->action) }}</span>
                                        <div class="small text-body-secondary mt-1"><code>{{ $entry->action }}</code></div>
                                    </td>
                                    <td class="small">
                                        <div class="text-body-secondary">{{ $presenter::subjectLabel($entry->subject_type) }}</div>
                                        @if ($presenter::subjectUrl($entry->subject_type, $entry->subject_id))
                                            <a href="{{ $presenter::subjectUrl($entry->subject_type, $entry->subject_id) }}" class="fw-semibold">{{ $presenter->subjectTitle($entry) }}</a>
                                        @else
                                            <span class="fw-semibold">{{ $presenter->subjectTitle($entry) }}</span>
                                        @endif
                                        @if ($presenter->subjectName($entry) && $entry->subject_id !== null)
                                            <span class="text-body-secondary">#{{ $entry->subject_id }}</span>
                                        @endif
                                        <div>
                                            <a href="{{ route('audit-log.index', ['subject_type' => $entry->subject_type, 'subject_id' => $entry->subject_id]) }}"
                                                class="small text-body-secondary" title="{{ __('All entries about this record') }}">
                                                <i class="bi bi-filter"></i> {{ __('history') }}
                                            </a>
                                        </div>
                                    </td>
                                    <td>
                                        @include('audit-log.partials.changes', ['changes' => $entry->changes, 'presenter' => $presenter])
                                        @include('audit-log.partials.undone', ['undo' => $undoneBy[$entry->id] ?? null])
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="bi bi-search display-4 d-block mb-2"></i>
                                        {{ __('No entries found') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($entries->hasPages())
                <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <small class="text-muted">
                        {{ __(':count entries per page, newest first', ['count' => $entries->perPage()]) }}
                    </small>
                    {{ $entries->links('pagination::simple-bootstrap-5') }}
                </div>
            @endif
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
