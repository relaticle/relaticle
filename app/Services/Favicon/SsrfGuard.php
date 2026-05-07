<?php

declare(strict_types=1);

namespace App\Services\Favicon;

use App\Exceptions\SsrfGuardException;

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
