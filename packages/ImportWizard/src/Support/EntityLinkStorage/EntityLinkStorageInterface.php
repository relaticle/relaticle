<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support\EntityLinkStorage;

use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;

/**
 * Interface for entity link storage strategies.
 *
 * Different storage types (foreign key, morph-to-many, custom field values)
 * require different approaches to persist resolved entity links.
 */
interface EntityLinkStorageInterface
{
    /**
     * Store resolved entity link(s) for a record after save.
     *
     * Called in afterSave() for operations that require the saved record.
     *
     * @param  Model  $record  The saved record
     * @param  EntityLink  $link  The entity link configuration
     * @param  array<int|string>  $resolvedIds  The resolved target record ID(s)
     * @param  array<string, mixed>  $context  Additional context from import
     */
    public function store(Model $record, EntityLink $link, array $resolvedIds, array $context): void;

    /**
     * Prepare data for model fill() before save.
     *
     * Called in prepareForSave() for storage types that set model attributes.
     *
     * @param  array<string, mixed>  $data  Current prepared data
     * @param  EntityLink  $link  The entity link configuration
     * @param  array<int|string>  $resolvedIds  The resolved target record ID(s)
     * @return array<string, mixed> Modified data with entity link values
     */
    public function prepareData(array $data, EntityLink $link, array $resolvedIds): array;
}
