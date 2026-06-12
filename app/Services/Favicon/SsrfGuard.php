<?php

declare(strict_types=1);

namespace App\Services\Favicon;

use App\Exceptions\SsrfGuardException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

final readonly class SsrfGuard
{
    public static function isAllowed(string $url): bool
    {
        try {
            self::assertPublicHost($url);

            return true;
        } catch (SsrfGuardException $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * An HTTP client that re-validates every redirect hop against this guard.
     *
     * The underlying client follows redirects by default, so validating only the
     * initial URL would let an attacker-controlled public host redirect the request
     * to an internal address (SSRF, CWE-918). Callers must still validate the initial
     * URL with {@see self::isAllowed()} — the guard below only covers redirect hops.
     */
    public static function guardedHttpClient(): PendingRequest
    {
        return Http::withOptions(self::redirectGuardOptions());
    }

    /**
     * Guzzle options whose on_redirect callback aborts the request before any
     * non-public redirect target is contacted, reporting the block for parity
     * with the initial-URL check in {@see self::isAllowed()}.
     *
     * @return array{allow_redirects: array<string, mixed>}
     */
    public static function redirectGuardOptions(): array
    {
        return [
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'on_redirect' => static function (
                    RequestInterface $request,
                    ResponseInterface $response,
                    UriInterface $uri,
                ): void {
                    try {
                        self::assertPublicHost((string) $uri);
                    } catch (SsrfGuardException $exception) {
                        report($exception);

                        throw $exception;
                    }
                },
            ],
        ];
    }

    public static function assertPublicHost(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        throw_if(! is_string($host) || $host === '', SsrfGuardException::class, 'Invalid host in URL');

        $host = trim($host, '[]');

        $addresses = self::resolveAddresses($host);

        throw_if($addresses === [], SsrfGuardException::class, "Could not resolve host: {$host}");

        foreach ($addresses as $address) {
            throw_unless(self::isPublicAddress($address), SsrfGuardException::class, "Refusing to fetch from non-public address: {$address}");
        }
    }

    /**
     * @return list<string>
     */
    private static function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        return $addresses;
    }

    private static function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
