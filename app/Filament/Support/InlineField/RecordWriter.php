<?php

declare(strict_types=1);

namespace App\Filament\Support\InlineField;

use App\Actions\Company\UpdateCompany;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\People\UpdatePeople;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class RecordWriter
{
    public function __construct(
        private UpdateCompany $updateCompany,
        private UpdatePeople $updatePeople,
        private UpdateOpportunity $updateOpportunity,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(User $user, Model $record, array $payload): void
    {
        match (true) {
            $record instanceof Company => $this->updateCompany->execute($user, $record, $payload),
            $record instanceof People => $this->updatePeople->execute($user, $record, $payload),
            $record instanceof Opportunity => $this->updateOpportunity->execute($user, $record, $payload),
            default => abort(404),
        };
    }
}
