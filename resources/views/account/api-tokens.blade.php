{{-- "My account" -> API tokens ($apiTokens, $maxApiTokens: DataToolsServiceProvider view composer).
     The API requires export-data; without it only existing tokens can be revoked. --}}
@if (count($apiTokens) > 0 || $canCreateApiTokens)
<div class="card mt-4" id="api-tokens">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0 text-body-emphasis"><i class="bi bi-braces-asterisk me-2"></i>{{ __('API tokens') }}</h5>
        <span class="small text-body-secondary">{{ count($apiTokens) }} / {{ $maxApiTokens }}</span>
    </div>
    <div class="card-body">
        <p class="small text-body-secondary">
            {{ __('Personal access tokens for the REST API (/api/v1). A token can read what you can see in the directory - with your role, department and salary access. All your tokens are revoked when your password or role changes or your account is deactivated.') }}
        </p>

        @if (session('api_token_plain'))
            <div class="alert alert-warning" role="alert">
                <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>{{ __('Your new token (shown only once):') }}</div>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="newApiToken" value="{{ session('api_token_plain') }}" readonly>
                    <button class="btn btn-outline-secondary" type="button"
                        onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('newApiToken').value)">
                        <i class="bi bi-clipboard me-1"></i>{{ __('Copy') }}
                    </button>
                </div>
                <div class="small mt-1">{{ __('Send it as "Authorization: Bearer <token>".') }}</div>
            </div>
        @endif

        @can('export-data')
        <form method="POST" action="{{ route('account.tokens.store') }}" class="row g-2 align-items-end mb-3">
            @csrf
            <div class="col-md-5">
                <label for="token_name" class="form-label fw-semibold">{{ __('Token name') }}</label>
                <input type="text" id="token_name" name="token_name" value="{{ old('token_name') }}" maxlength="100" required
                    placeholder="{{ __('e.g. Reporting script') }}"
                    class="form-control @error('token_name', 'apiToken') is-invalid @enderror">
                @error('token_name', 'apiToken')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="col-md-4">
                <label for="expires_in" class="form-label fw-semibold">{{ __('Expires') }}</label>
                <select id="expires_in" name="expires_in" class="form-select @error('expires_in', 'apiToken') is-invalid @enderror">
                    @foreach ($apiTokenExpiryDays as $days)
                        <option value="{{ $days }}" @selected((string) old('expires_in', '90') === (string) $days)>{{ __('in :days days', ['days' => $days]) }}</option>
                    @endforeach
                    <option value="" @selected(old('expires_in') === '')>{{ __('never') }}</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100" @disabled(count($apiTokens) >= $maxApiTokens)>
                    <i class="bi bi-plus-lg me-1"></i>{{ __('Create token') }}
                </button>
            </div>
        </form>
        @endcan

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Created') }}</th>
                        <th>{{ __('Last used') }}</th>
                        <th>{{ __('Expires') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($apiTokens as $token)
                        <tr>
                            <td>{{ $token->name }}</td>
                            <td>{{ $token->created_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $token->last_used_at?->format('d.m.Y H:i') ?? __('never') }}</td>
                            <td>
                                @if ($token->expires_at === null)
                                    {{ __('never') }}
                                @elseif ($token->expires_at->isPast())
                                    <span class="badge bg-secondary">{{ __('expired') }}</span>
                                @else
                                    {{ $token->expires_at->format('d.m.Y') }}
                                @endif
                            </td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('account.tokens.destroy', $token->id) }}"
                                    onsubmit="return confirm('{{ __('Revoke this token? Programs using it lose access immediately.') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-x-circle me-1"></i>{{ __('Revoke') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-3">{{ __('No API tokens yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="small text-body-secondary mt-2 mb-0">
            {{ __('API documentation: docs/API.md in the repository.') }}
        </p>
    </div>
</div>
@endif
