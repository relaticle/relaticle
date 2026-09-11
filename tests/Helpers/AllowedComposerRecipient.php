<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;

final class AllowedComposerRecipient
{
    public static function seed(User $user, string $email): void
    {
        $team = $user->currentTeam;

        $person = People::factory()->for($team)->create([
            'creator_id' => $user->getKey(),
        ]);

        $emailsField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', 'people')
            ->where('code', PeopleField::EMAILS->value)
            ->firstOrFail();

        $person->saveCustomFieldValue($emailsField, [$email], $team);
    }

    /**
     * @param  list<string>  $emails
     */
    public static function seedMany(User $user, array $emails): void
    {
        foreach ($emails as $email) {
            self::seed($user, $email);
        }
    }
}
