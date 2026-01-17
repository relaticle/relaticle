<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Importers\Contracts;

use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizardNew\Importers\Fields\FieldCollection;

/**
 * Contract for entity importers.
 *
 * Importers are the single source of truth for import configuration.
 * They define fields, relationships, matching strategies, and processing logic.
 */
interface ImporterContract
{
    /**
     * Get the team ID for this import.
     */
    public function getTeamId(): string;

    /**
     * Get the fully-qualified model class name.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string;

    /**
     * Get the entity name identifier (e.g., 'company', 'people').
     */
    public function entityName(): string;

    /**
     * Get the standard fields for this entity.
     */
    public function fields(): FieldCollection;

    /**
     * Get all fields including custom fields.
     */
    public function allFields(): FieldCollection;

    /**
     * Get relationship definitions for this entity.
     *
     * @return array<string, \Relaticle\ImportWizardNew\Importers\Fields\RelationshipField>
     */
    public function relationships(): array;

    /**
     * Get fields that can be used to match imported rows to existing records.
     *
     * @return array<\Relaticle\ImportWizardNew\Data\MatchableField>
     */
    public function matchableFields(): array;

    /**
     * Get the highest priority matchable field that is mapped.
     *
     * @param  array<string>  $mappedFields  List of mapped field keys
     */
    public function getMatchFieldForMappedColumns(array $mappedFields): ?\Relaticle\ImportWizardNew\Data\MatchableField;

    /**
     * Whether this entity requires a unique identifier for matching.
     */
    public function requiresUniqueIdentifier(): bool;

    /**
     * Prepare data for saving to the database.
     *
     * This method transforms the mapped CSV data into model-ready data.
     * Override this to handle special cases like relationship resolution.
     *
     * @param  array<string, mixed>  $data  The mapped row data
     * @param  Model|null  $existing  The existing record if updating
     * @param  array<string, mixed>  $context  Additional context (e.g., match field info)
     * @return array<string, mixed> The prepared data ready for fill()
     */
    public function prepareForSave(array $data, ?Model $existing, array $context): array;

    /**
     * Perform post-save operations.
     *
     * Called after the record is saved. Use for relationship syncing,
     * polymorphic links, and other operations that require the saved record.
     *
     * @param  Model  $record  The saved record
     * @param  array<string, mixed>  $context  Additional context from prepareForSave
     */
    public function afterSave(Model $record, array $context): void;
}
