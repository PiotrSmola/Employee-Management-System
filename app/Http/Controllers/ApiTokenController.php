<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "My account" -> API tokens: users with export-data manage their own personal access
 * tokens. A token acts with its owner's role and scope; its plain text is
 * shown once, after creation.
 */
class ApiTokenController extends Controller
{
    /** Offered validity periods in days (null: no expiry). */
    public const EXPIRY_DAYS = [30, 90, 365];

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validateWithBag('apiToken', [
            'token_name' => ['required', 'string', 'max:100'],
            'expires_in' => ['nullable', Rule::in(array_map('strval', self::EXPIRY_DAYS))],
        ]);

        if ($user->tokens()->count() >= (int) config('data_tools.api.max_tokens', 20)) {
            return redirect()->route('account.edit')->withErrors(
                ['token_name' => __('You have reached the maximum number of API tokens. Revoke one first.')],
                'apiToken'
            );
        }

        $expiresAt = ! empty($validated['expires_in']) ? now()->addDays((int) $validated['expires_in']) : null;
        $token = $user->createToken($validated['token_name'], ['read'], $expiresAt);

        AuditLogger::log('data.api_token_created', ActivityLog::SUBJECT_USER, $user->id, [
            'token_id' => $token->accessToken->id,
            'name' => $validated['token_name'],
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
        ], $user);

        return redirect()->to(route('account.edit').'#api-tokens')
            ->with('api_token_plain', $token->plainTextToken)
            ->with('status', __('The API token has been created. Copy it now - it is not shown again.'));
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $user = $request->user();
        $model = $user->tokens()->whereKey($token)->firstOrFail();
        $model->delete();

        AuditLogger::log('data.api_token_revoked', ActivityLog::SUBJECT_USER, $user->id, [
            'token_id' => $model->id,
            'name' => $model->name,
        ], $user);

        return redirect()->to(route('account.edit').'#api-tokens')->with('status', __('The API token has been revoked.'));
    }
}
