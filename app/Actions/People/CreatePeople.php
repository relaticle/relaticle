<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Actions\LinkPersonCompanyFromEmails;

final readonly class CreatePeople
{
    public function __construct(
        private LinkPersonCompanyFromEmails $linkPersonCompanyFromEmails,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): People
    {
        abort_unless($user->can('create', People::class), 403);

        TenantFkValidator::assertOwned($user, $data, [
            'company_id' => Company::class,
        ]);

        $attributes = Arr::only($data, ['name', 'company_id', 'custom_fields']);
        $attributes['creation_source'] = $source;

        $person = DB::transaction(fn (): People => People::query()->create($attributes));

        $this->linkPersonCompanyFromEmails->execute($person->fresh());

        return $person->load('customFieldValues.customField.options');
    }
}
