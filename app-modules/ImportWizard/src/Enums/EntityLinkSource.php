<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

/**
 * Defines the source of an entity link definition.
 *
 * EntityLinks can come from two sources:
 * - Relationship: Hardcoded relationship definitions in importers
 * - CustomField: Record-type custom fields from the database
 */
enum EntityLinkSource: string
{
    case Relationship = 'relationship';
    case CustomField = 'custom_field';
}
