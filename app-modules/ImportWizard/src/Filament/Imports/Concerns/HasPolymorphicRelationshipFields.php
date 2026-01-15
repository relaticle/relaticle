<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports\Concerns;

use Relaticle\ImportWizard\Data\RelationshipField;
use Relaticle\ImportWizard\Data\RelationshipMatcher;

trait HasPolymorphicRelationshipFields
{
    /**
     * Build standard polymorphic relationship fields for Notes/Tasks.
     *
     * @param  string  $entityLabel  "Note" or "Task" for hint text
     * @return array<string, RelationshipField>
     */
    protected static function buildPolymorphicRelationshipFields(string $entityLabel): array
    {
        return [
            'linked_company' => new RelationshipField(
                name: 'linked_company',
                label: 'Linked Company',
                targetEntity: 'companies',
                icon: 'heroicon-o-building-office-2',
                matchers: RelationshipMatcher::collection([
                    new RelationshipMatcher(
                        key: 'id',
                        label: 'Record ID',
                        description: 'Link to company by ULID',
                        createsNew: false,
                        hint: "{$entityLabel} will be attached to this company",
                        guesses: ['company_id', 'linked_company_id'],
                        rules: ['nullable', 'string', 'ulid'],
                    ),
                    new RelationshipMatcher(
                        key: 'name',
                        label: 'Name',
                        description: 'Create new company with this name',
                        createsNew: true,
                        hint: 'Always creates new (name is not unique)',
                        guesses: ['company_name', 'linked_company'],
                        rules: ['nullable', 'string', 'max:255'],
                    ),
                ]),
                defaultMatcher: 'name',
            ),
            'linked_person' => new RelationshipField(
                name: 'linked_person',
                label: 'Linked Person',
                targetEntity: 'people',
                icon: 'heroicon-o-user',
                matchers: RelationshipMatcher::collection([
                    new RelationshipMatcher(
                        key: 'id',
                        label: 'Record ID',
                        description: 'Link to person by ULID',
                        createsNew: false,
                        hint: "{$entityLabel} will be attached to this person",
                        guesses: ['person_id', 'linked_person_id', 'people_id'],
                        rules: ['nullable', 'string', 'ulid'],
                    ),
                    new RelationshipMatcher(
                        key: 'email',
                        label: 'Email',
                        description: 'Match existing person by email',
                        createsNew: false,
                        hint: 'Email is unique - finds existing person',
                        guesses: ['person_email', 'linked_person_email'],
                        rules: ['nullable', 'email'],
                    ),
                    new RelationshipMatcher(
                        key: 'name',
                        label: 'Name',
                        description: 'Create new person with this name',
                        createsNew: true,
                        hint: 'Always creates new (name is not unique)',
                        guesses: ['person_name', 'linked_person'],
                        rules: ['nullable', 'string', 'max:255'],
                    ),
                ]),
                defaultMatcher: 'email',
            ),
            'linked_opportunity' => new RelationshipField(
                name: 'linked_opportunity',
                label: 'Linked Opportunity',
                targetEntity: 'opportunities',
                icon: 'heroicon-o-currency-dollar',
                matchers: RelationshipMatcher::collection([
                    new RelationshipMatcher(
                        key: 'id',
                        label: 'Record ID',
                        description: 'Link to opportunity by ULID',
                        createsNew: false,
                        hint: "{$entityLabel} will be attached to this opportunity",
                        guesses: ['opportunity_id', 'linked_opportunity_id', 'deal_id'],
                        rules: ['nullable', 'string', 'ulid'],
                    ),
                    new RelationshipMatcher(
                        key: 'name',
                        label: 'Name',
                        description: 'Create new opportunity with this name',
                        createsNew: true,
                        hint: 'Always creates new (name is not unique)',
                        guesses: ['opportunity_name', 'linked_opportunity', 'deal_name'],
                        rules: ['nullable', 'string', 'max:255'],
                    ),
                ]),
                defaultMatcher: 'name',
            ),
        ];
    }
}
