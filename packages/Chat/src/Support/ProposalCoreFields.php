<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

/**
 * Single source of truth for which keys are "core" (first-class entity columns,
 * not custom fields) on a chat create-proposal record, per entity type. Both the
 * server-side editor (ProposalEditor) and the docked card (ProposalCard) split
 * core from custom fields the same way, so keep that knowledge here and the two
 * sites can never drift.
 */
final readonly class ProposalCoreFields
{
    /**
     * The entity's primary title column: `title` for task/note, `email` for an
     * invitation (which has no name at all), `name` otherwise.
     */
    public static function titleKey(string $entityType): string
    {
        return match (true) {
            in_array($entityType, ['task', 'note'], true) => 'title',
            $entityType === 'team_invitations' => 'email',
            default => 'name',
        };
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

        if ($entityType === 'team_invitations') {
            return [$titleKey, 'role'];
        }

        return [$titleKey];
    }

    public static function isCore(string $entityType, string $code): bool
    {
        return in_array($code, self::keys($entityType), true);
    }
}
