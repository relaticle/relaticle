<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Support\EmailForAgent;

final readonly class ListEmailAccountsTool implements Tool
{
    public function __construct(private EmailForAgent $presenter) {}

    public function description(): string
    {
        return 'List the mailboxes the user has connected in this workspace, default first.'
            .' `can_send` is false for a mailbox that cannot send right now, such as one that only receives or needs reconnecting.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $items = ConnectedAccount::query()
            ->ownedBy($user, $user->currentWorkspace)
            ->connected()
            ->defaultFirst()
            ->get()
            ->map(fn (ConnectedAccount $account): array => $this->presenter->mailbox($account))
            ->all();

        return (string) json_encode([
            'items' => $items,
            'note' => ListEmailsTool::DATA_NOTE,
        ], JSON_UNESCAPED_SLASHES);
    }
}
