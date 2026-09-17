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
    /** @var list<array{url: string, params: array<string, mixed>}> */
    public array $requests = [];

    public static function install(): self
    {
        $recorder = new self;

        ApiRequestor::setHttpClient($recorder);

        return $recorder;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['url' => (string) $absUrl, 'params' => $params];

        $body = match (true) {
            str_contains((string) $absUrl, '/checkout/sessions') => [
                'id' => 'cs_test_fake',
                'object' => 'checkout.session',
                'client_secret' => 'cs_test_fake_secret',
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

    /** @return array<string, mixed> */
    public function paramsFor(string $urlFragment): array
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], $urlFragment)) {
                return $request['params'];
            }
        }

        throw new RuntimeException("No Stripe request matched url fragment [{$urlFragment}].");
    }
}
