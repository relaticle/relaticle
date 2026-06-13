<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

/**
 * Single source of truth for which keys are "core" (first-class entity columns,
 * not custom fields) on a chat create-proposal record, per entity type. Both the
 * server-side editor (ProposalEditor) and the docked card (ProposalCard) split
 * core from custom fields the same way — keep that knowledge here so the two
 * sites can never drift.
 */
final class ProposalCoreFields
{
    /**
     * The entity's primary title column: `title` for task/note, `name` otherwise.
     */
    public static function titleKey(string $entityType): string
    {
        return in_array($entityType, ['task', 'note'], true) ? 'title' : 'name';
    }

    /**
     * All core keys for the entity. Company additionally owns `account_owner_id`.
     *
     * @return list<string>
     */
    public static function keys(string $entityType): array
    {
        $titleKey = self::titleKey($entityType);

        if ($entityType === 'company') {
            return [$titleKey, 'account_owner_id'];
        }

        return [$titleKey];
    }

    public static function isCore(string $entityType, string $code): bool
    {
        return in_array($code, self::keys($entityType), true);
    }
}
