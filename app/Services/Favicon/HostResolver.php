<?php

declare(strict_types=1);

namespace App\Services\Favicon;

use Closure;

final readonly class HostResolver
{
    /** @param (Closure(string): list<string>)|null $lookup */
    public function __construct(private ?Closure $lookup = null) {}

    /**
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        if ($this->lookup instanceof Closure) {
            return ($this->lookup)($host);
        }

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
}
