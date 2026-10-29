<?php

declare(strict_types=1);

namespace Tests\Helpers;

use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stripe's SDK talks HTTP directly, so the only way to exercise the real
 * checkout path offline is to hand it a client that answers with a canned
 * object. Everything above it (Cashier, the action, the Livewire component)
 * runs for real. It also records every request so a test can assert on the
 * payload Cashier built.
 */
final class StripeRecorder implements ClientInterface
{
    /** @var list<array{method: string, url: string, params: array<string, mixed>, headers: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, array{body: array<string, mixed>, status: int}> */
    private array $responses = [];

    private static ?self $current = null;

    public static function install(): self
    {
        $recorder = new self;

        ApiRequestor::setHttpClient($recorder);
        self::$current = $recorder;

        return $recorder;
    }

    /** @param  array<string, mixed>  $body */
    /** @param  array<string, mixed>|string  $body */
    public function respondTo(string $urlFragment, array|string $body, int $status = 200): self
    {
        $this->responses[$urlFragment] = ['body' => $body, 'status' => $status];

        return $this;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
        self::$current = null;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => strtoupper((string) $method), 'url' => (string) $absUrl, 'params' => $params, 'headers' => $headers];

        foreach ($this->responses as $key => $response) {
            if ($this->matches((string) $key, strtoupper((string) $method), (string) $absUrl)) {
                return [is_string($response['body']) ? $response['body'] : json_encode($response['body']), $response['status'], []];
            }
        }

        $body = match (true) {
            strtoupper((string) $method) === 'GET' && preg_match('#/v1/invoices(\?|$)#', (string) $absUrl) === 1 => ['object' => 'list', 'has_more' => false, 'data' => []],
            str_contains((string) $absUrl, '/checkout/sessions') => [
                'id' => 'cs_test_fake',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_fake',
            ],
            str_contains((string) $absUrl, '/billing_portal/sessions') => [
                'id' => 'bps_test_fake',
                'object' => 'billing_portal.session',
                'url' => 'https://billing.stripe.com/p/session/test',
            ],
            str_contains((string) $absUrl, '/customers') => [
                'id' => 'cus_test_fake',
                'object' => 'customer',
                'email' => 'billing@example.test',
            ],
            default => ['id' => 'obj_test_fake', 'object' => 'object'],
        };

        return [json_encode($body), 200, []];
    }

    private function matches(string $key, string $method, string $url): bool
    {
        if (preg_match('/^(GET|POST|DELETE) (.+)$/', $key, $parts) === 1) {
            return $parts[1] === $method && str_contains($url, $parts[2]);
        }

        return str_contains($url, $key);
    }

    /** @return list<array{method: string, url: string}> */
    public function writesSince(int $offset): array
    {
        return array_values(array_map(
            fn (array $request): array => ['method' => $request['method'], 'url' => $request['url']],
            array_filter(
                array_slice($this->requests, $offset),
                fn (array $request): bool => $request['method'] !== 'GET' && ! str_contains($request['url'], '/v1/invoices/create_preview'),
            ),
        ));
    }

    /** @return list<string> */
    public function headersFor(string $urlFragment): array
    {
        foreach ($this->requests as $request) {
            if ($this->matches($urlFragment, $request['method'], $request['url'])) {
                return array_values($request['headers']);
            }
        }

        throw new RuntimeException("No Stripe request matched url fragment [{$urlFragment}].");
    }

    public function countFor(string $urlFragment): int
    {
        return count(array_filter(
            $this->requests,
            fn (array $request): bool => $this->matches($urlFragment, $request['method'], $request['url']),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function allParamsFor(string $urlFragment): array
    {
        return array_values(array_map(
            fn (array $request): array => $request['params'],
            array_filter(
                $this->requests,
                fn (array $request): bool => $this->matches($urlFragment, $request['method'], $request['url']),
            ),
        ));
    }

    /** @return array<string, mixed> */
    public function lastParamsFor(string $urlFragment): array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($this->matches($urlFragment, $request['method'], $request['url'])) {
                return $request['params'];
            }
        }

        throw new RuntimeException("No Stripe request matched url fragment [{$urlFragment}].");
    }

    /** @return array<string, mixed> */
    public function paramsFor(string $urlFragment): array
    {
        foreach ($this->requests as $request) {
            if ($this->matches($urlFragment, $request['method'], $request['url'])) {
                return $request['params'];
            }
        }

        throw new RuntimeException("No Stripe request matched url fragment [{$urlFragment}].");
    }
}
