<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\CreationSource;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Team;
use App\Support\Database\AdvisoryLock;
use Illuminate\Contracts\Database\Query\Builder;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;

final readonly class AutoCreatePersonAction
{
    public function __construct(private AdvisoryLock $advisoryLock) {}

    /**
     * Find-or-create a Person for the given email address.
     *
     * Identity is the email address, never the display name: two distinct people
     * who happen to share a name must stay distinct, and the same address reuses
     * the existing record rather than spawning a duplicate. The lock serialises
     * concurrent queue workers racing the same address so the check-then-create
     * stays atomic. New records use CreationSource::SYSTEM so they are
     * distinguishable from manually created ones.
     */
    public function execute(
        string $name,
        string $emailAddress,
        string $teamId,
        Team $team,
        ?string $companyId = null,
    ): People {
        $emailField = $this->customFieldByCode($teamId);

        return $this->advisoryLock->transactional("auto-create-person:{$teamId}:{$emailAddress}", function () use ($name, $emailAddress, $teamId, $team, $companyId, $emailField): People {
            $existing = $this->findByEmail($emailAddress, $teamId, $emailField);

            if ($existing instanceof People) {
                return $existing;
            }

            $person = People::query()->create([
                'name' => $name ?: $emailAddress,
                'team_id' => $teamId,
                'company_id' => $companyId,
                'creation_source' => CreationSource::SYSTEM,
            ]);

            if ($emailField instanceof BaseCustomField) {
                $person->saveCustomFieldValue($emailField, [$emailAddress], $team);
            }

            return $person;
        });
    }

    /**
     * Match an existing Person carrying this address in the people "emails"
     * custom field. Returns null when the field is unconfigured for the team —
     * there is then no canonical place email identity is stored, so the caller
     * falls through to creating a fresh record.
     */
    private function findByEmail(string $emailAddress, string $teamId, ?BaseCustomField $emailField): ?People
    {
        if (! $emailField instanceof BaseCustomField) {
            return null;
        }

        return People::query()
            ->where('team_id', $teamId)
            ->whereHas('customFieldValues', fn (Builder $valueQuery): Builder => $valueQuery
                ->where('custom_field_id', $emailField->getKey())
                ->whereJsonContains('json_value', $emailAddress)
            )
            ->first();
    }

    private function customFieldByCode(string $teamId): ?BaseCustomField
    {
        return CustomField::query()
            ->where('code', 'emails')
            ->where('entity_type', 'people')
            ->where('tenant_id', $teamId)
            ->first();
    }
}
