<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every response of the application.
 *
 * The Content-Security-Policy limits where a page may load from and send data
 * to, but it does not stop inline scripts:
 * - 'unsafe-inline' (scripts): the views use inline <script> blocks and onclick /
 *   onsubmit handlers. Handlers cannot carry a nonce, so they have to move to
 *   public/js before this can go.
 * - 'unsafe-eval': Livewire's Alpine evaluates wire:* expressions with Function().
 * - 'unsafe-inline' (styles): the layout's <style> block and style attributes.
 *
 * Still enforced: scripts, styles and fonts only from this site and jsDelivr,
 * requests and form posts only to this site, no plugins, no <base> rewriting,
 * no framing by other sites.
 */
class SecurityHeaders
{
    private const CDN = 'https://cdn.jsdelivr.net';

    private const CONTENT_SECURITY_POLICY = [
        'default-src' => "'self'",
        'script-src' => "'self' 'unsafe-inline' 'unsafe-eval' ".self::CDN,
        'style-src' => "'self' 'unsafe-inline' ".self::CDN,
        'font-src' => "'self' ".self::CDN,
        'img-src' => "'self' data:",
        'connect-src' => "'self'",
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'self'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
        ]);

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        return collect(self::CONTENT_SECURITY_POLICY)
            ->map(fn (string $sources, string $directive) => "{$directive} {$sources}")
            ->implode('; ');
    }
}
