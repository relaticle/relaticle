<?php

declare(strict_types=1);

namespace App\Support\Http;

use ArrayObject;
use Closure;

final readonly class HostResolver
{
    /**
     * @param  (Closure(string): list<string>)|null  $lookup
     * @param  ArrayObject<string, list<string>>  $answers
     */
    public function __construct(private ?Closure $lookup = null, private bool $remember = false, private ArrayObject $answers = new ArrayObject) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function remembering(Closure $callback): mixed
    {
        $previous = resolve(self::class);

        app()->instance(self::class, new self($previous->lookup, remember: true));

        try {
            return $callback();
        } finally {
            app()->instance(self::class, $previous);
        }
    }

    /**
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        if (! $this->remember) {
            return $this->query($host);
        }

        if (! $this->answers->offsetExists($host)) {
            $this->answers[$host] = $this->query($host);
        }

        return $this->answers[$host];
    }

    /**
     * @return list<string>
     */
    private function query(string $host): array
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
