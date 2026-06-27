<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs\Concerns;

use Illuminate\Http\Client\RequestException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

trait DetectsAuthErrors
{
    /**
     * Tokens that mark a failure as "the account must re-authenticate", matched
     * case-insensitively against every message in the exception chain. The first
     * group is provider-agnostic (RFC 6749 / generic); the rest are provider
     * specific. Adding a new mail/calendar provider is just a matter of appending
     * its auth-error markers here.
     *
     * @var list<string>
     */
    protected array $authErrorTokens = [
        // RFC 6749 OAuth2 error codes — apply to any OAuth2 provider.
        'invalid_grant',
        'invalid_client',
        'invalid_token',
        'unauthorized_client',
        // Generic / Laravel HTTP layer.
        'unauthenticated',
        'unauthorized',
        // Microsoft Entra ID (Azure AD).
        'AADSTS',
        // Google.
        'Token has been expired or revoked',
    ];

    /**
     * Decide whether a sync failure means the account must re-authenticate, rather
     * than guessing from a bare "401" substring (which matches ports, byte counts,
     * ids, etc.). Provider-agnostic: inspects the real HTTP status and known auth
     * tokens across the whole exception chain.
     */
    protected function isAuthError(Throwable $exception): bool
    {
        foreach ($this->throwableChain($exception) as $throwable) {
            if ($this->httpStatus($throwable) === 401) {
                return true;
            }

            $message = $throwable->getMessage();

            foreach ($this->authErrorTokens as $token) {
                if (stripos((string) $message, (string) $token) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract an HTTP status from any exception that carries an HTTP response,
     * independent of the client library (Laravel HTTP, Guzzle/PSR-18, or SDKs
     * such as the Google API client and Microsoft Graph that put it in the code).
     */
    private function httpStatus(Throwable $exception): ?int
    {
        // Laravel HTTP client.
        if ($exception instanceof RequestException) {
            return $exception->response->status();
        }

        // Guzzle / PSR-18 style: getResponse() returns a PSR-7 response.
        if (method_exists($exception, 'getResponse')) {
            $response = $exception->getResponse();

            if ($response instanceof ResponseInterface) {
                return $response->getStatusCode();
            }
        }

        // Fallback: many SDKs surface the HTTP status as the exception code.
        $code = $exception->getCode();

        return is_int($code) && $code >= 100 && $code < 600 ? $code : null;
    }

    /**
     * Yield the exception and each of its previous exceptions, so a wrapped auth
     * failure (e.g. a domain exception around a Guzzle 401) is still detected.
     *
     * @return iterable<Throwable>
     */
    private function throwableChain(Throwable $exception): iterable
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            yield $current;

            $current = $current->getPrevious();
        }
    }
}
