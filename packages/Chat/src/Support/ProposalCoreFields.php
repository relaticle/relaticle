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
     * Records approved whole: no field checkbox on the card and no field stripped at approval.
     *
     * @var list<string>
     */
    private const array INDIVISIBLE = ['emails', 'email_drafts'];

    /**
     * The entity's primary title column: `title` for task/note, `email` for an
     * invitation (which has no name at all), `subject` for an email, `name` otherwise.
     */
    public static function titleKey(string $entityType): string
    {
        return match (true) {
            in_array($entityType, ['task', 'note'], true) => 'title',
            $entityType === 'workspace_invitations' => 'email',
            self::isIndivisible($entityType) => 'subject',
            default => 'name',
        };
    }

    public static function isIndivisible(string $entityType): bool
    {
        return in_array($entityType, self::INDIVISIBLE, true);
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

        if ($entityType === 'workspace_invitations') {
            return [$titleKey, 'role'];
        }

        return [$titleKey];
    }

    public static function isCore(string $entityType, string $code): bool
    {
        return in_array($code, self::keys($entityType), true);
    }
}
