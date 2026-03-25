<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;

final readonly class EmailSharingService
{
    /**
     * Share a single email with a specific user at a given tier.
     */
    public function shareEmail(Email $email, User $sharedBy, User $sharedWith, EmailPrivacyTier $tier): EmailShare
    {
        return EmailShare::updateOrCreate(
            [
                'email_id' => $email->getKey(),
                'shared_with' => $sharedWith->getKey(),
            ],
            [
                'shared_by' => $sharedBy->getKey(),
                'tier' => $tier->value,
            ]
        );
    }

    /**
     * Revoke a share.
     */
    public function revokeShare(Email $email, User $sharedWith): void
    {
        EmailShare::where('email_id', $email->getKey())
            ->where('shared_with', $sharedWith->getKey())
            ->delete();
    }

    /**
     * Share all emails owned by $owner that are linked to $record with a specific user.
     */
    public function shareAllOnRecord(Model $record, User $owner, User $sharedWith, EmailPrivacyTier $tier): int
    {
        $emails = $this->ownerEmailsOnRecord($record, $owner);

        foreach ($emails as $email) {
            $this->shareEmail($email, $owner, $sharedWith, $tier);
        }

        return $emails->count();
    }

    /**
     * Update the privacy_tier on all emails owned by $owner that are linked to $record.
     */
    public function setTierForAllOnRecord(Model $record, User $owner, EmailPrivacyTier $tier): int
    {
        $emailIds = $this->ownerEmailsOnRecord($record, $owner)->modelKeys();

        if (empty($emailIds)) {
            return 0;
        }

        return Email::whereIn('id', $emailIds)->update(['privacy_tier' => $tier->value]);
    }

    /**
     * Update an email's own privacy_tier.
     */
    public function setEmailTier(Email $email, EmailPrivacyTier $tier): void
    {
        $email->update(['privacy_tier' => $tier]);
    }

    /**
     * Retrieve all emails owned by $owner that are linked to $record via the emailables pivot.
     *
     * @return Collection<int, Email>
     */
    private function ownerEmailsOnRecord(Model $record, User $owner): Collection
    {
        $linkedIds = DB::table('emailables')
            ->where('emailable_type', $record->getMorphClass())
            ->where('emailable_id', $record->getKey())
            ->pluck('email_id');

        return Email::where('user_id', $owner->getKey())
            ->whereIn('id', $linkedIds)
            ->get();
    }
}
