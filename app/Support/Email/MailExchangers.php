<?php

declare(strict_types=1);

namespace App\Support\Email;

use Closure;

final readonly class MailExchangers
{
    /**
     * @param  (Closure(string): list<string>)|null  $lookup
     */
    public function __construct(private ?Closure $lookup = null) {}

    /**
     * @return list<string>
     */
    public function hostsFor(string $domain): array
    {
        $hosts = $this->lookup instanceof Closure
            ? ($this->lookup)($domain)
            : $this->query($domain);

        return array_map(fn (string $host): string => rtrim(mb_strtolower($host), '.'), $hosts);
    }

    /**
     * @return list<string>
     */
    private function query(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX);

        if ($records === false) {
            return [];
        }

        $hosts = [];
        foreach ($records as $record) {
            if (isset($record['target'])) {
                $hosts[] = (string) $record['target'];
            }
        }

        return $hosts;
    }
}
