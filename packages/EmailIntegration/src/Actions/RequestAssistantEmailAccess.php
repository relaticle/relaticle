<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;

final readonly class RequestAssistantEmailAccess
{
    public function __construct(private VisibleEmailsQuery $emails, private RequestEmailAccessAction $request) {}

    /** @param array{email_id: string, tier_requested?: string, note?: ?string} $data */
    public function execute(User $user, array $data): EmailAccessRequest
    {
        $validated = Validator::make($data, [
            'email_id' => ['required', 'string'],
            'tier_requested' => ['sometimes', Rule::in([EmailPrivacyTier::SUBJECT->value, EmailPrivacyTier::FULL->value])],
            'note' => ['nullable', 'string', 'max:'.EmailAccessRequest::NOTE_MAX_LENGTH],
        ])->validate();

        $email = $this->emails->find($user, $validated['email_id']);

        if (! $email instanceof Email || ! $user->can('requestAccess', $email)) {
            throw ValidationException::withMessages(['email_id' => 'Choose a visible email whose subject or message is locked.']);
        }

        return $this->request->execute($email, $user, EmailPrivacyTier::from($validated['tier_requested'] ?? EmailPrivacyTier::FULL->value), $validated['note'] ?? null)
            ?? throw ValidationException::withMessages(['email_id' => 'Access has already been requested for this email.']);
    }
}
