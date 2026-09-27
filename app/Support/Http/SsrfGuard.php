<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Exceptions\SsrfGuardException;
use App\Exceptions\UploadException;
use App\Support\Media\UploadAllowlist;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;

final readonly class SsrfGuard
{
    /**
     * Ranges PHP's own filter reports as public. The translation prefixes matter
     * most: 2002::/16 and 64:ff9b::/96 each embed an IPv4 address, so they reach
     * loopback and RFC1918 on any host with IPv6.
     *
     * @var list<string>
     */
    private const array DENIED_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '2002::/16',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2001::/32',
        '2001:20::/28',
        '2001:db8::/32',
        '::/96',
        'fec0::/10',
    ];

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

    public static function guard(PendingRequest $request): PendingRequest
    {
        return $request
            ->withOptions([
                'allow_redirects' => ['max' => 5, 'strict' => true, 'referer' => false, 'protocols' => ['http', 'https']],
                'progress' => self::abortPastUploadLimit(...),
            ])
            ->withMiddleware(self::pinToValidatedAddress(...));
    }

    private static function abortPastUploadLimit(int $downloadTotal, int $downloaded): void
    {
        throw_if(max($downloadTotal, $downloaded) > UploadAllowlist::maxBytes(), UploadException::tooLarge(UploadAllowlist::maxBytes()));
    }

    // Runs inside the redirect middleware, so every hop connects to the address it
    // was validated against and a second DNS answer cannot rebind it.
    private static function pinToValidatedAddress(callable $handler): Closure
    {
        return static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $uri = $request->getUri();
            $host = trim($uri->getHost(), '[]');
            $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);

            try {
                $pin = self::pin($host, $port);
            } catch (SsrfGuardException $exception) {
                report($exception);

                throw $exception;
            }

            if ($pin !== null) {
                $options['curl'][CURLOPT_RESOLVE] = [$pin];
            }

            return $handler($request, $options);
        };
    }

    public static function pinnedClient(string $url): PendingRequest
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? 443) : null;

        throw_unless($scheme === 'https' && $port === 443, SsrfGuardException::class, 'Only https URLs on port 443 are allowed');

        $pin = self::pin(trim((string) ($parts['host'] ?? ''), '[]'), 443);

        return Http::withOptions([
            'allow_redirects' => false,
            'decode_content' => false,
            'connect_timeout' => 10,
            'timeout' => 30,
            'curl' => $pin === null ? [] : [CURLOPT_RESOLVE => [$pin]],
            'progress' => self::abortPastUploadLimit(...),
        ]);
    }

    public static function assertPublicHost(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        throw_if(! is_string($host) || $host === '', SsrfGuardException::class, 'Invalid host in URL');

        self::publicAddresses(trim($host, '[]'));
    }

    /**
     * @return list<string>
     */
    private static function publicAddresses(string $host): array
    {
        $addresses = self::resolveAddresses($host);

        throw_if($addresses === [], SsrfGuardException::class, "Could not resolve host: {$host}");

        foreach ($addresses as $address) {
            throw_unless(self::isPublicAddress($address), SsrfGuardException::class, "Refusing to fetch from non-public address: {$address}");
        }

        return $addresses;
    }

    // A CURLOPT_RESOLVE entry naming every validated address, so the connection cannot
    // reach one a later DNS answer returns. An address literal has no lookup to pin.
    private static function pin(string $host, int $port): ?string
    {
        $addresses = self::publicAddresses($host);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $pinned = implode(',', array_map(fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address, $addresses));

        return "{$host}:{$port}:{$pinned}";
    }

    /**
     * @return list<string>
     */
    private static function resolveAddresses(string $host): array
    {
        return resolve(HostResolver::class)->addresses($host);
    }

    private static function isPublicAddress(string $address): bool
    {
        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        return $public && ! IpUtils::checkIp($address, self::DENIED_RANGES);
    }
}
