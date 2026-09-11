<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Support\PersonRecipientFormatter;

final readonly class MassSendRecipientResolver
{
    /**
     * @param  Collection<int, People>  $people
     */
    public function resolveFromPeople(Collection $people): MassSendRecipientResult
    {
        if ($people->isEmpty()) {
            return new MassSendRecipientResult([], 0);
        }

        $teamId = (string) $people->first()->team_id;
        /** @var list<string> $peopleIds */
        $peopleIds = array_values($people
            ->map(fn (People $person): string => (string) $person->getKey())
            ->all());

        $emailByPersonId = [];

        foreach ($this->primaryEmailEntriesForPeople($peopleIds, $teamId) as $entry) {
            $emailByPersonId[$entry['person_id']] = $entry['email'];
        }

        /** @var list<array{person: People, email: string}> $recipients */
        $recipients = [];
        $skipped = 0;
        /** @var array<lowercase-string, true> $seenEmails */
        $seenEmails = [];

        foreach ($people as $person) {
            $email = $emailByPersonId[(string) $person->getKey()] ?? null;

            if ($email === null) {
                $skipped++;

                continue;
            }

            $normalizedEmail = strtolower($email);

            if (isset($seenEmails[$normalizedEmail])) {
                continue;
            }

            $seenEmails[$normalizedEmail] = true;
            $recipients[] = ['person' => $person, 'email' => $email];
        }

        return new MassSendRecipientResult($recipients, $skipped);
    }

    /**
     * @param  Collection<int, Company>  $companies
     */
    public function resolveFromCompanies(Collection $companies): MassSendRecipientResult
    {
        if ($companies->isEmpty()) {
            return new MassSendRecipientResult([], 0);
        }

        $teamId = (string) $companies->first()->team_id;
        $companyIds = $companies->pluck('id')->all();

        $people = People::query()
            ->where('team_id', $teamId)
            ->whereIn('company_id', $companyIds)
            ->get();

        return $this->resolveFromPeople($people);
    }

    /**
     * @param  list<string>  $peopleIds
     * @return list<array{person_id: string, email: string}>
     */
    private function primaryEmailEntriesForPeople(array $peopleIds, string $teamId): array
    {
        if ($peopleIds === []) {
            return [];
        }

        $emailField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', 'people')
            ->where('code', PeopleField::EMAILS->value)
            ->first();

        if (! $emailField instanceof CustomField) {
            return [];
        }

        $entries = [];

        foreach (CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', 'people')
            ->where('custom_field_id', $emailField->getKey())
            ->whereIn('entity_id', $peopleIds)
            ->get(['entity_id', 'json_value']) as $value) {
            $email = $this->primaryEmailFromValue($value->json_value);

            if ($email !== null) {
                $entries[] = [
                    'person_id' => (string) $value->entity_id,
                    'email' => $email,
                ];
            }
        }

        return $entries;
    }

    private function primaryEmailFromValue(mixed $value): ?string
    {
        return PersonRecipientFormatter::primaryEmailFromValue($value);
    }
}
