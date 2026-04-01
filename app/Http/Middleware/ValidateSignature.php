<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature as BaseValidateSignature;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed URL middleware with percent-encoding normalization.
 *
 * Email providers (Postmark, SendGrid, Mailgun) may decode percent-encoded
 * characters in URLs during HTML processing (e.g., %40 → @). Since the
 * signature was computed against the encoded form, verification fails.
 *
 * Delegates to Laravel's built-in middleware first, then falls back to
 * re-encoding query values before comparing.
 *
 * @see https://github.com/laravel/framework/issues/42979
 */
final readonly class ValidateSignature
{
    public function __construct(
        private BaseValidateSignature $base,
    ) {}

    /** @param  string  ...$args */
    public function handle(Request $request, Closure $next, mixed ...$args): Response
    {
        try {
            return $this->base->handle($request, $next, ...$args);
        } catch (InvalidSignatureException) {
            if ($this->hasValidNormalizedSignature($request, array_values($args))) {
                return $next($request);
            }

            throw new InvalidSignatureException;
        }
    }

    /**
     * Re-encode query values to match the percent-encoding used during signing,
     * then verify the signature against the normalized URL.
     *
     * @param  array<int, string>  $args
     */
    private function hasValidNormalizedSignature(Request $request, array $args): bool
    {
        $relative = isset($args[0]) && $args[0] === 'relative';
        $absolute = ! $relative;
        $url = $absolute ? $request->url() : '/'.$request->path();

        $rawQuery = (string) $request->server->get('QUERY_STRING');

        if ($rawQuery === '') {
            return false;
        }

        $normalized = [];

        foreach (explode('&', $rawQuery) as $part) {
            $equalsPos = strpos($part, '=');

            [$key, $value] = $equalsPos !== false
                ? [substr($part, 0, $equalsPos), substr($part, $equalsPos + 1)]
                : [$part, ''];

            if ($key === 'signature') {
                continue;
            }

            $normalized[] = $key.'='.rawurlencode(rawurldecode($value));
        }

        $original = rtrim("{$url}?".implode('&', $normalized), '?');

        $keys = [config('app.key'), ...config('app.previous_keys', [])];
        $signature = (string) $request->query('signature', '');

        $hasCorrectSignature = array_any(
            $keys,
            fn (string $key): bool => hash_equals(hash_hmac('sha256', $original, $key), $signature),
        );

        return $hasCorrectSignature && URL::signatureHasNotExpired($request);
    }
}
