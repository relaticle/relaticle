<?php

declare(strict_types=1);

use App\Enums\WorkspaceCapability;
use App\Support\Workspaces\RoleOptions;
use Relaticle\Documentation\Support\DocsRepository;

/**
 * @return array<string, array<string, string>>
 */
function parseHelpRoleMatrix(string $markdown): array
{
    if (! preg_match('/\| \| Owner \| Admin \| Member \| Viewer \|\n\|[-|]+\|\n((?:\|.*\|\n?)+)/', $markdown, $tableMatch)) {
        return [];
    }

    $rows = [];

    foreach (explode("\n", trim($tableMatch[1])) as $line) {
        if (! preg_match('/^\|\s*(.+?)\s*\|\s*(Yes|No)\s*\|\s*(Yes|No)\s*\|\s*(Yes|No)\s*\|\s*(Yes|No)\s*\|$/', $line, $row)) {
            continue;
        }

        $rows[$row[1]] = [
            'owner' => $row[2],
            'admin' => $row[3],
            'member' => $row[4],
            'viewer' => $row[5],
        ];
    }

    return $rows;
}

test('the help page matrix renders every WorkspaceCapability label with the map\'s own Yes/No cells', function (): void {
    $page = app(DocsRepository::class)->find('help/workspace/manage-members-and-roles');

    expect($page)->not->toBeNull();

    $rows = parseHelpRoleMatrix($page->body);
    $matrix = RoleOptions::matrix();

    expect($rows)->toHaveSameSize(WorkspaceCapability::cases());

    foreach (WorkspaceCapability::cases() as $capability) {
        $label = $capability->label();

        expect($rows)->toHaveKey($label);

        foreach (['owner', 'admin', 'member', 'viewer'] as $roleKey) {
            $expected = $matrix[$capability->value][$roleKey] ? 'Yes' : 'No';

            expect($rows[$label][$roleKey])->toBe(
                $expected,
                "[{$label}] x [{$roleKey}] should read \"{$expected}\" in the help page, matching RoleOptions::matrix().",
            );
        }
    }
});
