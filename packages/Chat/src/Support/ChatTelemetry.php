<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Sentry\Breadcrumb;
use Sentry\State\Scope;

final class ChatTelemetry
{
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
