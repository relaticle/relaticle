<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

final readonly class ComposeRecordRecipientResolver
{
    public function __construct(
        private MassSendRecipientResolver $massSendRecipientResolver,
    ) {}

    /**
     * @return list<string>
     */
    public function toAddressesFor(Model $record): array
    {
        $email = match (true) {
            $record instanceof People => $this->primaryEmailForPerson($record),
            $record instanceof Company => $this->primaryEmailForCompany($record),
            $record instanceof Opportunity => $this->primaryEmailForOpportunity($record),
            default => null,
        };

        if ($email === null) {
            return [];
        }

        return [$email];
    }

    private function primaryEmailForPerson(People $person): ?string
    {
        $result = $this->massSendRecipientResolver->resolveFromPeople(
            new EloquentCollection([$person]),
        );

        return $result->recipients[0]['email'] ?? null;
    }

    private function primaryEmailForCompany(Company $company): ?string
    {
        $result = $this->massSendRecipientResolver->resolveFromCompanies(
            new EloquentCollection([$company]),
        );

        return $result->recipients[0]['email'] ?? null;
    }

    private function primaryEmailForOpportunity(Opportunity $opportunity): ?string
    {
        $contact = $opportunity->contact;

        if (! $contact instanceof People) {
            return null;
        }

        return $this->primaryEmailForPerson($contact);
    }
}
