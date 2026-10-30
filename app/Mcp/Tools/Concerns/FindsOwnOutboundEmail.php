<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Enums\EmailGrant;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;

trait FindsOwnOutboundEmail
{
    use ChecksTokenAbility;

    public function shouldRegister(): bool
    {
        return $this->holdsAnyEmailGrant(EmailGrant::Send) && $this->roleAllowsAgentSend();
    }

    protected function ownOutboundEmail(Request $request): Email|Response
    {
        if (($denied = $this->denyIfTokenLacks(EmailGrant::Send)) instanceof Response) {
            return $denied;
        }

        if (! $this->roleAllowsAgentSend()) {
            return Response::error('Your role in this workspace cannot send email through an assistant.');
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var array{id: string} $validated */
        $validated = $request->validate(['id' => ['required', 'string', 'max:64']]);

        $email = Email::query()
            ->composedBy($user)
            ->whereNot('status', EmailStatus::DRAFT)
            ->whereKey($validated['id'])
            ->first();

        return $email instanceof Email ? $email : Response::error("Email with ID [{$validated['id']}] not found in your outbox.");
    }

    /** @return array{id: string, status: string, scheduled_for: ?string} */
    protected function outboxState(Email $email): array
    {
        return [
            'id' => (string) $email->getKey(),
            'status' => $email->status->value,
            'scheduled_for' => $email->scheduled_for?->toIso8601String(),
        ];
    }
}
