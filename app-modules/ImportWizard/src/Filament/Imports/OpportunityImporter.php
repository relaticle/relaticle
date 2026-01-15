<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Models\Opportunity;
use Filament\Actions\Imports\ImportColumn;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Data\RelationshipField;
use Relaticle\ImportWizard\Filament\Imports\Concerns\HasCompanyRelationshipColumns;
use Relaticle\ImportWizard\Filament\Imports\Concerns\HasContactRelationshipColumns;

final class OpportunityImporter extends BaseImporter
{
    use HasCompanyRelationshipColumns;
    use HasContactRelationshipColumns;

    protected static ?string $model = Opportunity::class;

    protected static array $uniqueIdentifierColumns = ['id'];

    protected static string $missingUniqueIdentifiersMessage = 'For Opportunities, map a Record ID column';

    public static function getColumns(): array
    {
        return [
            self::buildIdColumn(),

            ImportColumn::make('name')
                ->label('Name')
                ->requiredMapping()
                ->guess([
                    'name', 'opportunity_name', 'title',
                    'deal name', 'deal_name', 'deal',
                    'opportunity name', 'opp_name', 'opp name',
                    'project', 'project_name', 'sale', 'sale_name', 'prospect',
                ])
                ->rules(['required', 'string', 'max:255'])
                ->example('Q1 Sales Opportunity')
                ->fillRecordUsing(function (Opportunity $record, string $state, OpportunityImporter $importer): void {
                    $record->name = $state;
                    $importer->initializeNewRecord($record);
                }),

            // Relationship columns for Company (hidden from dropdown, shown under "Link to Records")
            ...self::buildCompanyRelationshipColumns(),

            // Relationship columns for Contact (hidden from dropdown, shown under "Link to Records")
            ...self::buildContactRelationshipColumns(),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Opportunity
    {
        // ID-based matching only
        if ($this->hasIdValue()) {
            /** @var Opportunity|null $record */
            $record = $this->resolveById();

            return $record ?? new Opportunity;
        }

        // No match found - create new opportunity
        return new Opportunity;
    }

    public static function getEntityName(): string
    {
        return 'opportunity';
    }

    /**
     * @return array<string, RelationshipField>
     */
    public static function getRelationshipFields(): array
    {
        return [
            'company' => RelationshipField::company(),
            'contact' => RelationshipField::contact(),
        ];
    }
}
