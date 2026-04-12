<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\CreationSource;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Team;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;

final readonly class AutoCreatePersonAction
{
    /**
     * Create a new Person record seeded with name + email custom field value.
     * The person is created with CreationSource::SYSTEM so it is distinguishable
     * from manually created records.
     */
    public function execute(
        string $name,
        string $emailAddress,
        string $teamId,
        Team $team,
        ?string $companyId = null,
    ): People {
        $person = People::query()
            ->updateOrCreate([
                'name' => $name ?: $emailAddress,
                'team_id' => $teamId,
                'company_id' => $companyId,
                'creation_source' => CreationSource::SYSTEM,
            ]);

        $emailField = $this->customFieldByCode($teamId);

        if ($emailField instanceof BaseCustomField) {
            $person->saveCustomFieldValue($emailField, [$emailAddress], $team);
        }

        return $person;
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
