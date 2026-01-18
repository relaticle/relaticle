<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Importers;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizardNew\Data\ImportField;
use Relaticle\ImportWizardNew\Data\ImportFieldCollection;
use Relaticle\ImportWizardNew\Data\MatchableField;

/**
 * Importer for Company entities.
 *
 * Companies are standalone entities with domain-based matching support.
 */
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
                ->example('Acme Corporation'),

            ImportField::make('account_owner_email')
                ->label('Account Owner Email')
                ->rules(['nullable', 'email'])
                ->guess([
                    'account_owner', 'owner_email', 'owner', 'assigned_to', 'account_manager',
                    'owner email', 'sales rep', 'sales_rep', 'rep', 'salesperson', 'sales_owner',
                    'account_rep', 'assigned_user', 'manager_email', 'contact_owner',
                ])
                ->example('owner@company.com'),
        ]);
    }

    /**
     * @return array<MatchableField>
     */
    public function matchableFields(): array
    {
        return [
            MatchableField::id(),
            MatchableField::domain('custom_fields_domains'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array $context): array
    {
        $data = parent::prepareForSave($data, $existing, $context);

        $accountOwnerEmail = $data['account_owner_email'] ?? null;
        unset($data['account_owner_email']);

        if (filled($accountOwnerEmail)) {
            $user = $this->resolveTeamMemberByEmail($accountOwnerEmail);
            if ($user instanceof \App\Models\User) {
                $data['account_owner_id'] = $user->getKey();
            }
        }

        if (! $existing instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->initializeNewRecordData($data, $context['creator_id'] ?? null);
        }

        return $data;
    }
}
