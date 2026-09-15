<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Jobs\ProcessInboundEmailJob;

final readonly class InboundEmailWebhookController
{
    public function __invoke(Request $request, string $token): Response
    {
        if (! $this->authorized($token)) {
            return response('', Response::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $path = 'inbound-webhooks/'.Str::ulid().'.json';
        Storage::disk('local')->put($path, json_encode($payload, JSON_THROW_ON_ERROR));

        dispatch(new ProcessInboundEmailJob($path));

        return response('', Response::HTTP_OK);
    }

    private function authorized(string $token): bool
    {
        $secret = (string) config('email-integration.inbound.webhook_secret');

        if ($secret === '') {
            return app()->environment('local', 'testing');
        }

        return hash_equals($secret, $token);
    }
}
