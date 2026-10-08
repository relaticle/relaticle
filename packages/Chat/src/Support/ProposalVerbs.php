<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

final readonly class ProposalVerbs
{
    /** @var array<string, array{action: string, done: string, count: string, verb: string, notDone: string}> */
    private const array OPERATIONS = [
        'create' => ['action' => 'Create', 'done' => 'Created', 'count' => ':count created', 'verb' => 'create', 'notDone' => 'NOT created'],
        'update' => ['action' => 'Save changes', 'done' => 'Updated', 'count' => ':count updated', 'verb' => 'update', 'notDone' => 'NOT updated'],
        'delete' => ['action' => 'Delete', 'done' => 'Deleted', 'count' => ':count deleted', 'verb' => 'delete', 'notDone' => 'NOT deleted'],
    ];

    /** @var array<string, array{action: string, done: string, count: string, verb: string, notDone: string, noun: string}> */
    private const array ENTITIES = [
        'emails' => ['action' => 'Send', 'done' => 'Sent', 'count' => ':count sent', 'verb' => 'send', 'notDone' => 'NOT sent', 'noun' => 'email'],
        'email_drafts' => ['action' => 'Save draft', 'done' => 'Saved', 'count' => ':count saved', 'verb' => 'save', 'notDone' => 'NOT saved', 'noun' => 'email draft'],
        'workspace_invitations' => ['action' => 'Send invitation', 'done' => 'Invited', 'count' => ':count invited', 'verb' => 'invite', 'notDone' => 'NOT invited', 'noun' => 'teammate'],
    ];

    public static function action(string $entityType, string $operation): string
    {
        return __(self::entry($entityType, $operation)['action']);
    }

    public static function done(string $entityType, string $operation): string
    {
        return __(self::entry($entityType, $operation)['done']);
    }

    public static function count(string $entityType, string $operation): string
    {
        return __(self::entry($entityType, $operation)['count']);
    }

    public static function verb(string $entityType, string $operation): string
    {
        return self::entry($entityType, $operation)['verb'];
    }

    public static function noun(string $entityType): string
    {
        return self::ENTITIES[$entityType]['noun'] ?? $entityType;
    }

    public static function notDone(string $entityType, string $operation): string
    {
        return self::entry($entityType, $operation)['notDone'];
    }

    /**
     * @return array<string, array{done: string, count: string}>
     */
    public static function receiptsByEntity(): array
    {
        $receipts = [];

        foreach (array_keys(self::ENTITIES) as $entityType) {
            $receipts[$entityType] = [
                'done' => self::done($entityType, 'create'),
                'count' => self::count($entityType, 'create'),
            ];
        }

        return $receipts;
    }

    /**
     * @return array{action: string, done: string, count: string, verb: string, notDone: string}
     */
    private static function entry(string $entityType, string $operation): array
    {
        return self::ENTITIES[$entityType] ?? self::OPERATIONS[$operation] ?? self::OPERATIONS['create'];
    }
}
