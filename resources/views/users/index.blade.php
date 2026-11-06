@include('layouts.html')
@include('layouts.head', ['pageTitle' => __('Users') . ' - Employee Management System'])

<body style="display: flex; flex-direction: column; min-height: 100vh;">
    @include('layouts.navbar')

    <main class="container-xl py-4" style="flex: 1;">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <h1 class="h3 mb-0">
                <i class="bi bi-person-gear me-2 text-primary"></i>
                {{ __('Users') }}
            </h1>
            <a href="{{ route('users.create') }}" class="btn btn-success">
                <i class="bi bi-person-plus me-1"></i>{{ __('New user') }}
            </a>
        </div>

        @include('layouts.flash')

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="{{ route('users.index') }}" class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label for="search" class="form-label fw-semibold text-body-emphasis">{{ __('Search') }}</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="search" name="search" value="{{ $search }}" class="form-control"
                                placeholder="{{ __('Name or e-mail...') }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="role" class="form-label fw-semibold text-body-emphasis">{{ __('Role') }}</label>
                        <select id="role" name="role" class="form-select">
                            <option value="">{{ __('All roles') }}</option>
                            @foreach (\App\Enums\Role::cases() as $option)
                                <option value="{{ $option->value }}" @selected($role === $option)>{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-funnel me-1"></i>{{ __('Filter') }}
                        </button>
                        @if ($search !== '' || $role)
                            <a href="{{ route('users.index') }}" class="btn btn-outline-secondary" title="{{ __('Reset') }}">
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
                                <th scope="col">{{ __('Name') }}</th>
                                <th scope="col">{{ __('E-mail') }}</th>
                                <th scope="col">{{ __('Role') }}</th>
                                <th scope="col">{{ __('Department') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col">{{ __('Last sign-in') }}</th>
                                <th scope="col" class="text-end">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($users as $user)
                                <tr>
                                    <td class="fw-semibold">
                                        {{ $user->name }}
                                        @if ($user->is(auth()->user()))
                                            <span class="badge role-badge text-bg-light border ms-1">{{ __('you') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $user->email }}</td>
                                    <td>
                                        <span class="badge role-badge {{ $user->role->badgeClass() }}">{{ $user->role->label() }}</span>
                                    </td>
                                    <td>
                                        @if ($user->role->isDepartmentScoped())
                                            {{ $user->department?->dept_name ?? ($user->dept_no ?? '-') }}
                                        @else
                                            <span class="text-body-secondary">{{ __('All') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($user->is_active)
                                            <span class="badge role-badge text-bg-success">{{ __('Active') }}</span>
                                        @else
                                            <span class="badge role-badge text-bg-secondary">{{ __('Inactive') }}</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $user->last_login_at?->format('d.m.Y H:i') ?? '-' }}</td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-primary"
                                            title="{{ __('Edit') }}">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" action="{{ route('users.destroy', $user) }}" class="d-inline"
                                            onsubmit="return confirm(@js(__('Delete the account of :name?', ['name' => $user->name])))">
                                            @csrf
                                            @method('DELETE')
                                            <span class="d-inline-block" @if ($deletionBlocked[$user->id]) tabindex="0" title="{{ $deletionBlocked[$user->id] }}" @endif>
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    title="{{ __('Delete') }}" @disabled($deletionBlocked[$user->id])>
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </span>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="bi bi-search display-4 d-block mb-2"></i>
                                        {{ __('No users found') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($users->hasPages())
                <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <small class="text-muted">
                        {{ __('Showing :from-:to of :total', ['from' => $users->firstItem(), 'to' => $users->lastItem(), 'total' => $users->total()]) }}
                    </small>
                    {{ $users->links('pagination::bootstrap-5') }}
                </div>
            @endif
        </div>
    </main>

    @include('layouts.footer', ['fixedBottom' => false])
</body>

</html>
