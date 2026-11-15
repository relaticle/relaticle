<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Streaming\Events\Error;
use Relaticle\Chat\Exceptions\ProviderStreamRejectedException;
use Sentry\Breadcrumb;
use Sentry\State\Scope;
use Throwable;

final class ChatTelemetry
{
    private const int FAILURE_BODY_LIMIT = 2000;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function breadcrumb(string $stage, array $data = []): void
    {
        \Sentry\addBreadcrumb(new Breadcrumb(
            level: Breadcrumb::LEVEL_INFO,
            type: Breadcrumb::TYPE_DEFAULT,
            category: 'chat',
            message: $stage,
            metadata: $data,
        ));
    }

    public static function providerRejected(RequestException $e, string $model): void
    {
        $type = $e->response->json('error.type');
        $message = $e->response->json('error.message');

        self::logRejection($model, $e->response->status(), is_string($type) ? $type : null, is_string($message) ? $message : null);
    }

    public static function streamRejected(Error $error, string $model): void
    {
        self::logRejection($model, null, $error->type, $error->message);
    }

    public static function providerFailed(Throwable $e, string $model): void
    {
        self::logRejection($model, null, class_basename($e), Str::limit($e->getMessage(), 500));
    }

    /**
     * @return array{class: ?string, status: ?int, body: ?string, provider: ?string, model: ?string}
     */
    public static function failureEvidence(?Throwable $e, ?string $provider, ?string $model): array
    {
        $rejection = $e instanceof RequestException ? $e : $e?->getPrevious();
        $response = $rejection instanceof RequestException ? $rejection->response : null;

        $body = match (true) {
            $response !== null => self::responseBody($response),
            $e instanceof ProviderStreamRejectedException => "{$e->error->type}: {$e->error->message}",
            default => null,
        };
        $body = $body === null ? '' : mb_substr(mb_scrub($body), 0, self::FAILURE_BODY_LIMIT);

        return [
            'class' => $e instanceof Throwable ? $e::class : null,
            'status' => $response?->status(),
            'body' => $body === '' ? null : $body,
            'provider' => $provider,
            'model' => $model,
        ];
    }

    // The gateways stream the response and read it with json() first, which drains
    // a non-seekable body. Only the cached decode still holds it afterwards.
    private static function responseBody(Response $response): string
    {
        $decoded = $response->json();

        return is_array($decoded)
            ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''
            : $response->body();
    }

    private static function logRejection(string $model, ?int $status, ?string $type, ?string $message): void
    {
        $context = [
            'model' => $model,
            'status' => $status,
            'error_type' => $type,
            'error_message' => $message,
        ];

        self::breadcrumb('stream.provider_rejected', $context);
        Log::warning('Chat provider rejected the turn', $context);
    }

    public static function tagCurrentScope(string $conversationId, string $workspaceId, string $model): void
    {
        \Sentry\configureScope(function (Scope $scope) use ($conversationId, $workspaceId, $model): void {
            $scope->setTag('chat.conversation_id', $conversationId);
            $scope->setTag('chat.workspace_id', $workspaceId);
            $scope->setTag('chat.model', $model);
        });
    }

    public static function rateLimited(string $workspaceId, string $plan): void
    {
        \Sentry\addBreadcrumb(new Breadcrumb(
            level: Breadcrumb::LEVEL_INFO,
            type: Breadcrumb::TYPE_DEFAULT,
            category: 'chat.rate_limit',
            message: 'rate_limited',
            metadata: [
                'workspace_id' => $workspaceId,
                'plan' => $plan,
            ],
        ));
    }
}
