<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\Validator;

final readonly class AddEmailAddressToPerson
{
    public function __construct(private UpdatePeople $updatePeople) {}

    public function execute(User $user, People $person, string $address): People
    {
        abort_unless($user->can('update', $person), 403);

        $address = EmailAddress::canonicalize($address);
        Validator::make(['address' => $address], ['address' => ['required', 'email']])->validate();

        $current = $this->addresses($person);

        if (in_array($address, array_map(EmailAddress::canonicalize(...), $current), true)) {
            return $person;
        }

        return $this->updatePeople->execute($user, $person, ['custom_fields' => [PeopleField::EMAILS->value => [...$current, $address]]]);
    }

    /** @return list<string> */
    private function addresses(People $person): array
    {
        $field = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $person->workspace_id)
            ->where('entity_type', $person->getMorphClass())
            ->where('code', PeopleField::EMAILS->value)
            ->first();

        $value = $field instanceof CustomField ? $person->loadMissing('customFieldValues.customField')->getCustomFieldValue($field) : null;

        return array_values(array_filter(
            is_iterable($value) ? array_map(fn (mixed $item): string => is_string($item) ? $item : '', [...$value]) : [],
            fn (string $item): bool => $item !== '',
        ));
    }
}
