<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Email;

use App\Enums\EmailGrant;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\EmailIntegration\Actions\DeleteEmailDraftAction;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;

#[Title('Delete Email Draft')]
#[Description('Delete one of the current user\'s saved drafts for good, with its attached files. Nothing is sent.')]
final class DeleteEmailDraftTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;

    public function shouldRegister(): bool
    {
        return $this->holdsAnyEmailGrant(EmailGrant::Draft);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->string()->description('The draft ID, as returned by the create email draft tool or listed with status draft.')->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'deleted' => $schema->boolean()->required(),
            'id' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request, DeleteEmailDraftAction $deleteDraft): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenLacks(EmailGrant::Draft)) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var array{draft_id: string} $validated */
        $validated = $request->validate(['draft_id' => ['required', 'string', 'max:64']]);

        $exists = Email::query()->composedBy($user)->where('status', EmailStatus::DRAFT)->whereKey($validated['draft_id'])->exists();

        if (! $exists) {
            return Response::error("Draft with ID [{$validated['draft_id']}] not found.");
        }

        $deleteDraft->execute($user, $validated['draft_id']);

        return Response::structured(['deleted' => true, 'id' => $validated['draft_id']]);
    }
}
