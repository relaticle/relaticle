<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Relaticle\EmailIntegration\Jobs\ProcessInboundEmailJob;

final readonly class InboundEmailWebhookController
{
    public function __invoke(Request $request): Response
    {
        if (! $this->authorized($request)) {
            return response('', Response::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        dispatch(new ProcessInboundEmailJob($payload));

        return response('', Response::HTTP_OK);
    }

    private function authorized(Request $request): bool
    {
        $secret = (string) config('email-integration.inbound.webhook_secret');

        if ($secret === '') {
            return app()->environment('local', 'testing');
        }

        $provided = $request->header('X-Relaticle-Inbound-Secret');

        return is_string($provided) && hash_equals($secret, $provided);
    }
}
