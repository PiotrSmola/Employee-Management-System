<?php

namespace App\Http\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every error of the REST API has the same shape:
 *
 *   {"error": {"status": 404, "code": "not_found", "message": "Employee not found."}}
 *
 * plus "fields" ({"per_page": ["..."]}) for validation errors (422). Server errors
 * never expose exception details.
 */
final class ApiErrorRenderer
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        500 => 'server_error',
    ];

    public static function render(Throwable $e): JsonResponse
    {
        if ($e instanceof AuthenticationException) {
            return self::response(401, __('Unauthenticated. Send a valid API token: "Authorization: Bearer <token>".'));
        }

        if ($e instanceof ValidationException) {
            return self::response(422, __('The given parameters are invalid.'), ['fields' => $e->errors()]);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = match ($status) {
                404 => $e->getMessage() !== '' && ! str_contains($e->getMessage(), 'No query results') && ! str_contains($e->getMessage(), 'could not be found')
                    ? $e->getMessage()
                    : __('Not found.'),
                403 => $e->getMessage() !== '' ? $e->getMessage() : __('This action is unauthorized.'),
                429 => __('Too many requests. Please slow down.'),
                default => $e->getMessage() !== '' ? $e->getMessage() : (JsonResponse::$statusTexts[$status] ?? __('Error')),
            };

            return self::response($status, $message, [], $e->getHeaders());
        }

        // Already reported by the exception handler; no details in the response.

        return self::response(500, __('Server error.'));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => [
            'status' => $status,
            'code' => self::CODES[$status] ?? 'error',
            'message' => $message,
        ] + $extra], $status, $headers);
    }
}
