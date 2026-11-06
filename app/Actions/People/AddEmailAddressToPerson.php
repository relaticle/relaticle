<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class AddEmailAddressToPerson
{
    public function __construct(private UpdatePeople $updatePeople) {}

    public function execute(User $user, People $person, string $address): People
    {
        abort_unless($user->can('update', $person), 403);

        $address = EmailAddress::canonicalize($address);
        Validator::make(['address' => $address], ['address' => ['required', 'email']])->validate();

        return DB::transaction(function () use ($user, $person, $address): People {
            $locked = People::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $person->workspace_id)->whereKey($person->getKey())->lockForUpdate()->firstOrFail();
            $current = $this->addresses($locked);

            if (in_array($address, array_map(EmailAddress::canonicalize(...), $current), true)) {
                return $locked;
            }

            return $this->updatePeople->execute($user, $locked, ['custom_fields' => [PeopleField::EMAILS->value => [...$current, $address]]]);
        });
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
