<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Importers\CompanyImporter;
use Relaticle\ImportWizard\Importers\NoteImporter;
use Relaticle\ImportWizard\Importers\OpportunityImporter;
use Relaticle\ImportWizard\Importers\PeopleImporter;
use Relaticle\ImportWizard\Importers\TaskImporter;

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
            self::Company => __('enums/import-entity-type.company'),
            self::People => __('enums/import-entity-type.people'),
            self::Opportunity => __('enums/import-entity-type.opportunity'),
            self::Task => __('enums/import-entity-type.task'),
            self::Note => __('enums/import-entity-type.note'),
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

    public function icon(): string
    {
        return match ($this) {
            self::Company => 'lucide-building',
            self::People => 'lucide-user',
            self::Opportunity => 'lucide-circle-dollar-sign',
            self::Task => 'lucide-clipboard-check',
            self::Note => 'lucide-file-text',
        };
    }

    public function importer(string $workspaceId): BaseImporter
    {
        $class = $this->importerClass();

        return new $class($workspaceId);
    }
}
