<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Data\MatchableField;

final class CompanyImporter extends BaseImporter
{
    public function modelClass(): string
    {
        return Company::class;
    }

    public function entityName(): string
    {
        return 'company';
    }

    public function fields(): ImportFieldCollection
    {
        return new ImportFieldCollection([
            ImportField::id(),

            ImportField::make('name')
                ->label('Name')
                ->required()
                ->rules(['required', 'string', 'max:255'])
                ->guess([
                    'name', 'company_name', 'company', 'organization', 'account', 'account_name',
                    'company name', 'associated company', 'company domain name',
                    'account name', 'parent account', 'billing name',
                    'business', 'business_name', 'org', 'org_name', 'organisation',
                    'firm', 'client', 'customer', 'customer_name', 'vendor', 'vendor_name',
                ])
                ->example('Acme Corporation')
                ->icon('heroicon-o-building-office-2'),
        ]);
    }

    /** @return array<string, EntityLink> */
    protected function defineEntityLinks(): array
    {
        return [
            'account_owner' => EntityLink::belongsTo('account_owner', User::class)
                ->matchableFields([
                    MatchableField::id(),
                    MatchableField::email('email'),
                ])
                ->foreignKey('account_owner_id')
                ->label('Account Owner')
                ->guess([
                    'account_owner', 'owner_email', 'owner', 'assigned_to', 'account_manager',
                    'owner email', 'sales rep', 'sales_rep', 'rep', 'salesperson', 'sales_owner',
                    'account_rep', 'assigned_user', 'manager_email', 'contact_owner',
                    'account_owner_email', 'owner_id',
                ]),
        ];
    }

    /** @return array<MatchableField> */
    public function matchableFields(): array
    {
        return [
            MatchableField::id(),
            MatchableField::domain('custom_fields_domains'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  &$context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array &$context): array
    {
        $data = parent::prepareForSave($data, $existing, $context);

        if (! $existing instanceof Model) {
            return $this->initializeNewRecordData($data, $context['creator_id'] ?? null);
        }

        return $data;
    }
}
