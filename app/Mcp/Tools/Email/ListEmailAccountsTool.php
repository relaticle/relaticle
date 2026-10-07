<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Email;

use App\Enums\EmailGrant;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

#[Title('List Email Accounts')]
#[Description('List the mailboxes the current user has connected in this workspace. Use an `id` as `connected_account_id` when drafting or sending. `can_send` is false for a mailbox that cannot send right now, such as one that only receives or needs reconnecting.')]
final class ListEmailAccountsTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;

    public function shouldRegister(): bool
    {
        return $this->holdsAnyEmailGrant(EmailGrant::Draft, EmailGrant::Send);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object())->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! $this->holdsAnyEmailGrant(EmailGrant::Draft, EmailGrant::Send)) {
            return Response::error('This connection has no email access.');
        }

        /** @var User $user */
        $user = auth()->user();

        $items = ConnectedAccount::query()
            ->ownedBy($user, $user->currentWorkspace)
            ->connected()
            ->defaultFirst()
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
                'id' => (string) $account->getKey(),
                'email' => $account->email_address,
                'name' => $account->display_name,
                'provider' => $account->provider->value,
                'is_default' => (bool) $account->is_default,
                'can_send' => $account->isSendable(),
            ])
            ->all();

        return Response::structured(['items' => $items]);
    }
}
