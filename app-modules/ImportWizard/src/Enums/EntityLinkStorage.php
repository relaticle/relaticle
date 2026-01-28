<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

/**
 * Defines how an entity link is stored in the database.
 *
 * Different link types require different storage strategies:
 * - ForeignKey: Direct FK column (e.g., company_id)
 * - MorphToMany: Junction table with polymorphic relationship
 * - CustomFieldValue: Stored in custom_field_values table
 */
enum EntityLinkStorage: string
{
    case ForeignKey = 'foreign_key';
    case MorphToMany = 'morph_to_many';
    case CustomFieldValue = 'custom_field_value';
}
