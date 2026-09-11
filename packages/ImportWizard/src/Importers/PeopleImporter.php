<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Relaticle\EmailIntegration\Actions\LinkPersonCompanyFromEmails;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Data\MatchableField;

/**
 * Importer for People entities.
 *
 * People can be matched by email or phone, and linked to companies.
 */
final class PeopleImporter extends BaseImporter
{
    /** @var list<string> */
    private array $pendingCompanyLinkPersonIds = [];

    public function modelClass(): string
    {
        return People::class;
    }

    public function entityName(): string
    {
        return 'people';
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
                    'name', 'full_name', 'person_name',
                    'contact', 'contact_name', 'person', 'individual', 'member', 'employee',
                    'full name', 'display_name', 'displayname',
                    'contact name', 'lead name', 'prospect name',
                ])
                ->example('John Doe')
                ->icon('heroicon-o-user'),
        ]);
    }

    /**
     * @return array<string, EntityLink>
     */
    protected function defineEntityLinks(): array
    {
        return [
            'company' => EntityLink::company(),
        ];
    }

    /**
     * @return array<MatchableField>
     */
    public function matchableFields(): array
    {
        return [
            MatchableField::id(),
            MatchableField::email('custom_fields_emails'),
            MatchableField::phone('custom_fields_phone_number'),
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

    /**
     * @param  array<string, mixed>  $context
     */
    public function afterSave(Model $record, array $context): void
    {
        parent::afterSave($record, $context);

        if ($record instanceof People) {
            $this->pendingCompanyLinkPersonIds[] = $record->getKey();
        }
    }

    public function linkPendingCompaniesFromEmails(): void
    {
        if ($this->pendingCompanyLinkPersonIds === []) {
            return;
        }

        $linker = resolve(LinkPersonCompanyFromEmails::class);

        foreach ($this->pendingCompanyLinkPersonIds as $personId) {
            $person = People::query()->find($personId);

            if ($person instanceof People) {
                $linker->execute($person);
            }
        }

        $this->pendingCompanyLinkPersonIds = [];
    }
}
