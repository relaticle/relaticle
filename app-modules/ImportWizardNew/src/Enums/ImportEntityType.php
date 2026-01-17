<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Enums;

use Relaticle\ImportWizardNew\Importers\BaseImporter;
use Relaticle\ImportWizardNew\Importers\CompanyImporter;
use Relaticle\ImportWizardNew\Importers\NoteImporter;
use Relaticle\ImportWizardNew\Importers\OpportunityImporter;
use Relaticle\ImportWizardNew\Importers\PeopleImporter;
use Relaticle\ImportWizardNew\Importers\TaskImporter;

enum ImportEntityType: string
{
    case Company = 'company';
    case People = 'people';
    case Opportunity = 'opportunity';
    case Task = 'task';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Companies',
            self::People => 'People',
            self::Opportunity => 'Opportunities',
            self::Task => 'Tasks',
            self::Note => 'Notes',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Company => 'Company',
            self::People => 'Person',
            self::Opportunity => 'Opportunity',
            self::Task => 'Task',
            self::Note => 'Note',
        };
    }

    /**
     * Get the importer class for this entity type.
     *
     * @return class-string<BaseImporter>
     */
    public function importerClass(): string
    {
        return match ($this) {
            self::Company => CompanyImporter::class,
            self::People => PeopleImporter::class,
            self::Opportunity => OpportunityImporter::class,
            self::Task => TaskImporter::class,
            self::Note => NoteImporter::class,
        };
    }

    /**
     * Create an importer instance for this entity type.
     */
    public function importer(string $teamId): BaseImporter
    {
        $class = $this->importerClass();

        return new $class($teamId);
    }
}
