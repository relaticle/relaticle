<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\EmailLinkTargetsQuery;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;

final readonly class UnlinkAssistantEmail
{
    public function __construct(
        private VisibleEmailsQuery $emails,
        private EmailLinkTargetsQuery $targets,
        private UnlinkEmailFromRecord $unlink,
    ) {}

    /** @param  array{email_id: string, record_type: string, record_id: string}  $data */
    public function execute(User $user, array $data): Email
    {
        $email = $this->emails->find($user, $data['email_id'])
            ?? throw ValidationException::withMessages(['email_id' => 'This email is no longer visible to you.']);

        $record = $this->targets->find($user, $data['record_type'], $data['record_id'])
            ?? throw ValidationException::withMessages(['record_id' => 'This record no longer exists in the workspace.']);

        $this->unlink->execute($user, $email, $record);

        return $email;
    }
}
