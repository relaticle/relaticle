<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Mcp\Servers\RelaticleServer;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilderContract;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Guards documented conventions that pest-arch and PHPStan cannot express.
 * Each check enforces a rule stated in .ai/guidelines/relaticle/.
 */

/** @return list<string> */
function migrationFiles(): array
{
    $root = dirname(__DIR__, 2);

    $directories = [
        $root.'/database/migrations',
        ...glob($root.'/packages/*/database/migrations', GLOB_ONLYDIR) ?: [],
    ];

    $files = [];

    foreach ($directories as $directory) {
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    return $files;
}

/**
 * @param  list<string>  $directories
 * @return list<string>
 */
function phpFilesUnder(array $directories): array
{
    $root = dirname(__DIR__, 2).'/';
    $files = [];

    foreach ($directories as $directory) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = str_replace($root, '', $file->getPathname());
            }
        }
    }

    return $files;
}

it('keeps migrations forward-only (no down methods)', function (): void {
    $offenders = array_values(array_filter(
        migrationFiles(),
        fn (string $file): bool => str_contains((string) file_get_contents($file), 'function down('),
    ));

    expect($offenders)->toBe(
        [],
        'Migrations are forward-only (.ai/guidelines/relaticle/core.md). Remove down() from: '.implode(', ', $offenders),
    );
});

it('queues only commands that exist from migrations', function (): void {
    $declared = [];

    $commandFiles = [
        ...(glob(dirname(__DIR__, 2).'/app/Console/Commands/*.php') ?: []),
        ...(glob(dirname(__DIR__, 2).'/packages/*/src/Console/Commands/*.php') ?: []),
    ];

    foreach ($commandFiles as $file) {
        if (preg_match('/#\[Signature\(\s*\'([a-z0-9:_-]+)/i', (string) file_get_contents($file), $match) === 1) {
            $declared[] = $match[1];
        }
    }

    $offenders = [];

    foreach (migrationFiles() as $file) {
        preg_match_all('/Artisan::queue\(\s*\'([^\']+)\'/', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $name) {
            if (! in_array($name, $declared, true)) {
                $offenders[] = basename($file).': '.$name;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'A migration outlives the command it queues (.ai/guidelines/relaticle/core.md). Restore or rename: '.implode(', ', $offenders),
    );
});

it('delays every command a migration queues', function (): void {
    $offenders = array_values(array_filter(
        migrationFiles(),
        fn (string $file): bool => str_contains((string) file_get_contents($file), 'Artisan::queue(')
            && ! str_contains((string) file_get_contents($file), '->delay('),
    ));

    expect(array_map(basename(...), $offenders))->toBe(
        [],
        'A worker booted before the deploy does not know a new command and fails the job (.ai/guidelines/relaticle/core.md). Add ->delay(now()->addMinutes(5)).',
    );
});

it('keeps migrations off the database clock (no useCurrent, CURRENT_TIMESTAMP, or raw now())', function (): void {
    $grandfathered = [
        '0001_01_01_000002_create_jobs_table.php',
    ];

    $offenders = array_values(array_filter(
        migrationFiles(),
        fn (string $file): bool => ! in_array(basename($file), $grandfathered, true)
            && preg_match(
                '/->useCurrent(OnUpdate)?\s*\(|CURRENT_TIMESTAMP|DB::raw\([^)]*\bnow\s*\(\s*\)/i',
                (string) file_get_contents($file),
            ) === 1,
    ));

    expect($offenders)->toBe(
        [],
        'Migrations must not write datetimes from the database clock; it resolves against the session timezone, not UTC (.ai/guidelines/relaticle/core.md). Use a PHP-side now() in: '.implode(', ', $offenders),
    );
});

it('keeps migrations out of the test suite', function (): void {
    $root = dirname(__DIR__, 2);

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests', FilesystemIterator::SKIP_DOTS));

    $offenders = [];

    foreach ($files as $file) {
        $path = $file->getPathname();

        if ($file->getExtension() !== 'php' || str_starts_with($path, $root.'/tests/Arch/')) {
            continue;
        }

        if (preg_match('#database/migrations/|database_path\(\s*[\'"]migrations#', (string) file_get_contents($path)) === 1) {
            $offenders[] = str_replace($root.'/', '', $path);
        }
    }

    expect($offenders)->toBe(
        [],
        'Migrations are not tested (.ai/guidelines/relaticle/testing.md). Remove the migration test from: '.implode(', ', $offenders),
    );
});

it('keeps compiled agent guidelines in sync with their .ai sources', function (): void {
    $root = dirname(__DIR__, 2);

    $sources = glob($root.'/.ai/guidelines/relaticle/*.md') ?: [];

    expect($sources)->not->toBe([]);

    foreach (['CLAUDE.md', 'AGENTS.md', 'GEMINI.md'] as $compiled) {
        $compiledContent = (string) file_get_contents($root.'/'.$compiled);

        foreach ($sources as $source) {
            $relativeSource = str_replace($root.'/', '', $source);

            expect(str_contains($compiledContent, trim((string) file_get_contents($source))))->toBeTrue(
                "{$compiled} is stale. It no longer contains the current content of {$relativeSource}. ".
                'Run `php artisan boost:update`, then copy AGENTS.md to GEMINI.md (boost does not write it).',
            );
        }
    }
});

it('keeps each Boost guideline override a copy of the bundled file minus its pinned lines', function (): void {
    $root = dirname(__DIR__, 2);

    $removedLines = [
        'laravel/core' => ["- When creating tests, make use of `{{ \$assist->artisanCommand('make:test [options] {name}') }}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests."],
        'pest/core' => ["- After the feature tests pass, ask the user to run the complete suite with `{{ \$assist->artisanCommand('test --compact') }}`."],
        'php/core' => ['- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.'],
    ];

    $overrides = array_map(
        fn (string $path): string => str_replace([$root.'/.ai/guidelines/', '.blade.php'], '', $path),
        glob($root.'/.ai/guidelines/*/*.blade.php') ?: [],
    );

    expect($overrides)->toBe(
        array_keys($removedLines),
        'Every Boost guideline override pins the bundled lines it removes (.ai/guidelines/relaticle/workflow.md).',
    );

    foreach ($removedLines as $key => $removed) {
        $bundled = file("{$root}/vendor/laravel/boost/.ai/{$key}.blade.php", FILE_IGNORE_NEW_LINES) ?: [];
        $override = file("{$root}/.ai/guidelines/{$key}.blade.php", FILE_IGNORE_NEW_LINES) ?: [];

        expect(array_values(array_diff($bundled, $override)))->toBe(
            $removed,
            "Boost changed {$key}. Copy the bundled file over .ai/guidelines/{$key}.blade.php again, then delete only the pinned lines.",
        );

        expect(array_values(array_diff($override, $bundled)))->toBe(
            [],
            ".ai/guidelines/{$key}.blade.php adds text. An override only deletes lines, and project rules go in .ai/guidelines/relaticle/.",
        );
    }
});

it('keeps every action on the canonical single-execute() shape', function (): void {
    $root = dirname(__DIR__, 2);

    $predatesTheCheck = [
        'Relaticle\Chat\Actions\StoreChatAttachment',
        'Relaticle\EmailIntegration\Actions\ApplyDefaultSharingTierToExistingEmailsAction',
        'Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction',
        'Relaticle\EmailIntegration\Actions\DeleteEmailDraftAction',
        'Relaticle\EmailIntegration\Actions\LinkEmailAction',
    ];

    $violations = [];

    foreach (phpFilesUnder([$root.'/app/Actions', ...glob($root.'/packages/*/src/Actions', GLOB_ONLYDIR) ?: []]) as $path) {
        if (str_contains($path, '/Actions/Fortify/') || str_contains($path, '/Actions/Jetstream/')) {
            continue;
        }

        $class = str_starts_with($path, 'app/')
            ? 'App\\'.str_replace(['/', '.php'], ['\\', ''], mb_substr($path, mb_strlen('app/')))
            : 'Relaticle\\'.str_replace(['/src/', '/', '.php'], ['\\', '\\', ''], mb_substr($path, mb_strlen('packages/')));

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isInterface() || $reflection->isAbstract()) {
            continue;
        }

        if (str_starts_with((string) ($reflection->getParentClass() ?: null)?->getName(), 'Laravel\\')) {
            continue;
        }

        $publicMethods = array_values(array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class,
            ),
        ));

        $unexpected = array_diff($publicMethods, ['__construct', 'execute']);

        if ($unexpected !== [] || ! in_array('execute', $publicMethods, true)) {
            $violations[$class] = implode(', ', $publicMethods);
        }
    }

    expect(array_diff_key($violations, array_flip($predatesTheCheck)))->toBe(
        [],
        'Actions expose exactly one public method, execute() (.ai/guidelines/relaticle/architecture.md). Fix: '.json_encode($violations),
    );

    expect(array_values(array_diff($predatesTheCheck, array_keys($violations))))->toBe(
        [],
        'These actions now have the single-execute() shape. Remove them from $predatesTheCheck.',
    );
});

it('names an action for what it does, with no Action suffix', function (): void {
    $root = dirname(__DIR__, 2);

    $predatesTheRule = [
        'ApplyDefaultSharingTierToExistingEmailsAction',
        'ApproveEmailAccessRequestAction',
        'AutoCreateCompanyAction',
        'AutoCreatePersonAction',
        'CancelEmailAccessRequestAction',
        'CancelQueuedEmailAction',
        'CompleteMailboxHistoryImportAction',
        'ConnectAccountAction',
        'CreateEmailTemplateAction',
        'CreateSignatureAction',
        'DeleteDraftAttachmentAction',
        'DeleteEmailDraftAction',
        'DeleteEmailTemplatesAction',
        'DeleteSignatureAction',
        'DeleteWorkspaceEmailVisibilityEntryAction',
        'DenyEmailAccessRequestAction',
        'DisconnectConnectedAccountAction',
        'LinkEmailAction',
        'LinkMeetingAction',
        'LinkMeetingToRecordAction',
        'MarkEmailAsReadAction',
        'MarkEmailsSendFailedAction',
        'QueueAgentEmailAction',
        'ReconcileCalendarMeetingsAction',
        'RequestEmailAccessAction',
        'RescheduleQueuedEmailAction',
        'RespondToMeetingAction',
        'RetryFailedEmailAction',
        'RetryMailboxHistoryImportFailuresAction',
        'SaveEmailDraftAction',
        'SaveWorkspaceEmailSharingDefaultAction',
        'SendEmailAction',
        'SendEmailBatchAction',
        'SetDefaultConnectedAccountAction',
        'StartMailboxHistoryImportAction',
        'StopCalendarPushChannelAction',
        'StoreEmailAction',
        'StoreMeetingAction',
        'SyncEmailBatchCountersAction',
        'SyncEmailThreadAction',
        'UnlinkMeetingFromRecordAction',
        'UpdateConnectedAccountBlocklistAction',
        'UpdateConnectedAccountSettingsAction',
        'UpdateEmailSharingAction',
        'UpdateSignatureAction',
        'UpdateWorkspaceContactCreationSettingsAction',
        'UpdateWorkspaceEmailPrivacySettingsAction',
        'UpdateWorkspaceEmailVisibilityAction',
        'UpdateWorkspaceEmailVisibilityEntryAction',
        'UpdateWorkspaceEmailVisibilityEntrySubdomainsAction',
        'UpdateWorkspaceProtectedRecipientsAction',
    ];

    $suffixed = array_values(array_filter(
        array_map(
            static fn (string $file): string => basename($file, '.php'),
            phpFilesUnder([$root.'/app/Actions', ...glob($root.'/packages/*/src/Actions', GLOB_ONLYDIR) ?: []]),
        ),
        static fn (string $name): bool => str_ends_with($name, 'Action'),
    ));

    expect(array_values(array_diff($suffixed, $predatesTheRule)))->toBe(
        [],
        'An action is named for what it does, with no Action suffix (.ai/guidelines/relaticle/architecture.md).',
    );

    expect(array_values(array_diff($predatesTheRule, $suffixed)))->toBe(
        [],
        'These actions no longer carry the suffix. Remove them from $predatesTheRule.',
    );
});

it('keeps reusable query predicates on their model as scopes', function (): void {
    $root = dirname(__DIR__, 2);

    $builders = [EloquentBuilder::class, QueryBuilder::class, EloquentBuilderContract::class, QueryBuilderContract::class];

    $allowed = [
        'Relaticle\SystemAdmin\Filament\Support\PivotSafeTableQuery::apply',
        'Relaticle\EmailIntegration\Services\PreferredEmailCopyService::restrictToVisiblePreferredCopies',
        'Relaticle\EmailIntegration\Services\EmailSearchService::applyToQuery',
        'Relaticle\EmailIntegration\Services\EmailSearchService::whereSubjectVisibleTo',
        'Relaticle\EmailIntegration\Support\BlocklistDomainMatcher::constrainWhereExistsDomainMatch',
    ];

    $sources = ['App\\' => $root.'/app/'];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $directory) {
        $sources['Relaticle\\'.basename(dirname($directory)).'\\'] = $directory.'/';
    }

    $violations = [];

    foreach ($sources as $namespace => $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], mb_substr((string) $file, mb_strlen($directory)));

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isEnum()
                || $reflection->isSubclassOf(Model::class)
                || $reflection->isSubclassOf(EloquentBuilder::class)
                || $reflection->implementsInterface(Scope::class)) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getFileName() !== $reflection->getFileName() || $method->hasPrototype()) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
                    $names = array_map(fn (?ReflectionType $named): string => $named instanceof ReflectionNamedType ? $named->getName() : '', $types);

                    if (array_intersect($names, $builders) !== []) {
                        $violations[] = "{$class}::{$method->getName()}";

                        break;
                    }
                }
            }
        }
    }

    $violations = array_values(array_diff($violations, $allowed));

    expect($violations)->toBe(
        [],
        'A reusable query predicate is a #[Scope] on its model, read from DB::table callers through ->toBase() (.ai/guidelines/relaticle/architecture.md). Fix: '.implode(', ', $violations),
    );
});

it('forces a conscious arch-coverage decision when a package is added', function (): void {
    $root = dirname(__DIR__, 2);

    /** @var array{autoload: array{"psr-4": array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    $packageNamespaces = [];

    foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
        if (str_starts_with($path, 'packages/')) {
            $packageNamespaces[] = rtrim($namespace, '\\');
        }
    }

    sort($packageNamespaces);

    expect($packageNamespaces)->toBe(
        [
            'Relaticle\Chat',
            'Relaticle\Documentation',
            'Relaticle\EmailIntegration',
            'Relaticle\ImportWizard',
            'Relaticle\OnboardSeed',
            'Relaticle\SystemAdmin',
        ],
        'Package list changed. Wire the new namespace into tests/Arch/ArchTest.php (boundary + structure rules), '.
        'the package table in .ai/guidelines/relaticle/architecture.md, and then update this list.',
    );
});

it('keeps the mutable Carbon class out of the codebase', function (): void {
    $root = dirname(__DIR__, 2);
    $self = __FILE__;

    $directories = [
        $root.'/app',
        $root.'/bootstrap',
        $root.'/config',
        $root.'/database',
        $root.'/packages',
        $root.'/routes',
        $root.'/tests',
    ];

    $offenders = [];

    foreach ($directories as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getPathname() === $self) {
                continue;
            }

            $lines = explode("\n", (string) file_get_contents($file->getPathname()));

            foreach ($lines as $index => $line) {
                $trimmed = mb_ltrim($line);

                $isComment = str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '/*');

                $declaresType = preg_match('/@(property|param|return|var)\b/', $trimmed) === 1;

                if ($isComment && ! $declaresType) {
                    continue;
                }

                if (preg_match('/\\bCarbon\\b(?!\\\\)/', $line) !== 1) {
                    continue;
                }

                $offenders[] = str_replace($root.'/', '', $file->getPathname()).':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Dates are immutable application-wide (.ai/guidelines/relaticle/core.md). The mutable Carbon '.
        'class no longer matches what the date factory builds, so a type hint becomes a TypeError and an '.
        'instanceof check silently turns false. Use CarbonImmutable, or CarbonInterface where a vendor '.
        'may still hand you a mutable date. '.
        'Offending lines: '.implode(', ', array_slice($offenders, 0, 40)),
    );
});

it('reads workspace authorization only through the capability map', function (): void {
    $root = dirname(__DIR__, 2);
    $self = __FILE__;

    $allowedFiles = [
        'app/Models/User.php',
        'app/Models/Concerns/HasWorkspaces.php',
        'app/Enums/WorkspaceRole.php',
        'app/Actions/Jetstream/RemoveWorkspaceMember.php',
        'tests/Feature/CRM/WorkspaceAuthorizationTest.php',
        'tests/Feature/Workspaces/InviteLinkTokenTest.php',
        'tests/Feature/Workspaces/RemoveWorkspaceMemberTest.php',
        'tests/Feature/Workspaces/UpdateWorkspaceMemberRoleTest.php',
        'tests/Feature/Workspaces/WorkspaceMembersCrossTenantTest.php',
        'tests/Feature/Workspaces/WorkspaceMembersTest.php',
    ];

    $directories = [
        $root.'/app',
        $root.'/packages',
        $root.'/database',
        $root.'/resources',
        $root.'/routes',
        $root.'/tests',
    ];

    $roleKeys = implode('|', array_map(
        fn (WorkspaceRole $role): string => preg_quote($role->value, '/'),
        WorkspaceRole::cases(),
    ));

    $pattern = '/('
        .'hasWorkspaceRole\(|hasWorkspaceRoleForWorkspaceId\(|isViewerOnWorkspaceId\(|ownsWorkspace\(|workspaceRole\(|membershipRole\('
        .'|WorkspaceRole::[A-Za-z]+(?:->value)?\s*(?:===|!==)'
        .'|(?:===|!==)\s*WorkspaceRole::[A-Za-z]+(?:->value)?'
        .'|(?:->role\b|\[\'role\'\]|->key\b|\$\w+|\))\s*(?:===|!==)\s*\'(?:'.$roleKeys.')\''
        .'|\'(?:'.$roleKeys.')\'\s*(?:===|!==)\s*(?:->role\b|\[\'role\'\]|->key\b|\$\w+)'
        .')/';

    $offenders = [];

    foreach ($directories as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getPathname() === $self) {
                continue;
            }

            $relativePath = str_replace($root.'/', '', $file->getPathname());

            if (in_array($relativePath, $allowedFiles, true)) {
                continue;
            }

            $lines = explode("\n", (string) file_get_contents($file->getPathname()));

            foreach ($lines as $index => $line) {
                $trimmed = mb_ltrim($line);

                $isComment = str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '/*');

                if ($isComment) {
                    continue;
                }

                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }

                $offenders[] = $relativePath.':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Workspace authorization is a role-string check outside the capability map '.
        '(.ai/guidelines/relaticle/architecture.md). Only App\\Models\\User, HasWorkspaces and '.
        'WorkspaceRole may resolve a role or ownership directly; every other caller reads '.
        'User::hasWorkspaceCapability(). '.
        'Offending lines: '.implode(', ', array_slice($offenders, 0, 40)),
    );
});

it('keeps the retired editor role key out of everything but its historic migrations', function (): void {
    $root = dirname(__DIR__, 2);

    $allowedPrefixes = [
        'database/migrations/2026_08_18_100000_',
        'database/migrations/2026_09_01_203744_',
        'database/migrations/2026_09_18_000000_',
    ];

    $directories = [
        $root.'/app',
        $root.'/config',
        $root.'/database',
        $root.'/lang',
        $root.'/packages',
        $root.'/resources',
        $root.'/routes',
    ];

    $offenders = [];

    foreach ($directories as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.(php|md|js|json)$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relativePath = str_replace($root.'/', '', $file->getPathname());

            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($relativePath, $prefix)) {
                    continue 2;
                }
            }

            $lines = explode("\n", (string) file_get_contents($file->getPathname()));

            foreach ($lines as $index => $line) {
                if (preg_match('/(?<![\w-]=)([\'"])editor\1/', $line) !== 1) {
                    continue;
                }

                $offenders[] = $relativePath.':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'The editor workspace role was renamed to member by 2026_09_18_000000_rename_editor_role_to_member. '.
        'Only the historic migrations may still name the old key; an HTML attribute such as x-ref is exempt. '.
        'Offending lines: '.implode(', ', array_slice($offenders, 0, 40)),
    );
});

it('keeps the retired team word out of identifiers', function (): void {
    $root = dirname(__DIR__, 2);

    $jetstreamNames = [
        'AddingTeam', 'AddingTeamMember', 'AddsTeamMembers', 'CreatesTeams', 'DeletesTeams', 'HasTeams',
        'InvitesTeamMembers', 'InvitingTeamMember', 'RemovesTeamMembers', 'TeamCreated', 'TeamMemberAdded',
        'TeamMemberRemoved', 'TeamMemberUpdated', 'UpdatesTeamNames', 'addTeamMembersUsing', 'createTeamsUsing',
        'deleteTeamsUsing', 'inviteTeamMembersUsing', 'newTeamModel', 'removeTeamMembersUsing',
        'updateTeamNamesUsing', 'useTeamInvitationModel', 'useTeamModel',
    ];

    $vendorVocabularyPaths = [
        'app/Models/Concerns/HasWorkspaces.php',
        'app/Support/Migrations/TenantMigration.php',
        'resources/views/teams/',
    ];

    $stripeMetadataKeyPaths = [
        'app/Actions/Billing/CreateCreditPackCheckout.php',
        'app/Http/Controllers/Billing/StripeWebhookController.php',
    ];

    $offenders = [];

    foreach (['app', 'config', 'database/factories', 'database/seeders', 'lang', 'packages', 'resources', 'routes'] as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)),
            '/\.php$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relativePath = str_replace($root.'/', '', $file->getPathname());

            if (str_contains($relativePath, '/database/migrations/')) {
                continue;
            }

            if (array_any($vendorVocabularyPaths, fn (string $path): bool => str_starts_with($relativePath, $path))) {
                continue;
            }

            preg_match_all(
                '/\$team\w*|(?<![\\\\\w])\w*[a-z]Team\w*|\bTeam[A-Z]\w*|\b\w*team_\w+|\b\w+_team\b/',
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            foreach (array_unique($matches[0]) as $token) {
                if (preg_match('/teammate|companyteam|company_team/i', $token) === 1) {
                    continue;
                }

                if (in_array($token, $jetstreamNames, true)) {
                    continue;
                }

                if ($token === 'team_id' && in_array($relativePath, $stripeMetadataKeyPaths, true)) {
                    continue;
                }

                $offenders[] = $relativePath.': '.$token;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'The tenant is a workspace (.ai/guidelines/relaticle/architecture.md). The word team survives only in '.
        'Jetstream names, in teammate, and in a company\'s team. Rename: '.implode(', ', array_slice($offenders, 0, 40)),
    );
});

it('keeps the retired contact and deal words out of record copy', function (): void {
    $root = dirname(__DIR__, 2);

    $labelPathsNamingSomethingElse = [
        'app/Support/MarketingNavigation.php',
        'resources/views/alternatives/',
    ];

    $emailParticipantPaths = [
        'lang/en/filament/pages/email-privacy-settings.php',
        'packages/Documentation/resources/content/help/email-and-calendar/set-workspace-email-privacy.md',
    ];

    $offenders = [];

    foreach (['app', 'config', 'database/factories', 'database/seeders', 'lang', 'packages', 'resources', 'routes'] as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)),
            '/\.(php|md|js|json)$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relativePath = str_replace($root.'/', '', $file->getPathname());

            $checksLabels = ! array_any($labelPathsNamingSomethingElse, fn (string $path): bool => str_starts_with($relativePath, $path));
            $checksProse = preg_match('#^(lang|resources/views|resources/js|packages/\w+/resources)/#', $relativePath) === 1
                && ! in_array($relativePath, $emailParticipantPaths, true);

            foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $index => $line) {
                $retired = [];

                if ($checksLabels && preg_match('/([\'"])(?:Contacts?|Deals?)\1/', $line, $label) === 1) {
                    $retired[] = $label[0];
                }

                if ($checksProse && preg_match('/\bcontacts\b|\ba contact\b(?! form)/i', $line, $recordNoun) === 1) {
                    $retired[] = $recordNoun[0];
                }

                if ($checksProse && preg_match('/\b[Yy]our people\b(?! export)/', $line, $possessive) === 1) {
                    $retired[] = $possessive[0];
                }

                foreach ($retired as $word) {
                    $offenders[] = $relativePath.':'.($index + 1).' '.$word;
                }
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'A person record is a person or people, and Opportunity labels the record (.ai/guidelines/relaticle/architecture.md, '.
        'Business language). "Your people" reads as the reader\'s staff (.ai/guidelines/relaticle/writing.md). '.
        'Offending lines: '.implode(', ', array_slice($offenders, 0, 40)),
    );
});

it('names the assistant one way and keeps the documented MCP tool count true', function (): void {
    $root = dirname(__DIR__, 2);
    $registered = count((new ReflectionClass(RelaticleServer::class))->getProperty('tools')->getDefaultValue());

    $paths = [$root.'/README.md'];

    foreach (['lang', 'resources/views', 'resources/data', 'packages'] as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)),
            '/\.(php|md|js)$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relativePath = str_replace($root.'/', '', $file->getPathname());

            if ($directory !== 'packages' || preg_match('#^packages/\w+/resources/#', $relativePath) === 1) {
                $paths[] = $file->getPathname();
            }
        }
    }

    $offenders = [];

    foreach ($paths as $path) {
        $relativePath = str_replace($root.'/', '', $path);

        foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
            $location = $relativePath.':'.($index + 1).' ';

            if (preg_match('/\bAI chat\b/i', $line, $retiredName) === 1) {
                $offenders[] = $location.$retiredName[0];
            }

            if (preg_match('/provides (\d+) tools/', $line, $count) === 1 && (int) $count[1] !== $registered) {
                $offenders[] = $location.$count[0];
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'The assistant is named by chat.assistant_name, never "AI chat", and the MCP guide counts '.
        "{$registered} tools, the number RelaticleServer registers (.ai/guidelines/relaticle/writing.md). ".
        'Offending lines: '.implode(', ', array_slice($offenders, 0, 40)),
    );
});

it('keeps published copy and source free of em-dashes', function (): void {
    $root = dirname(__DIR__, 2);
    $emDash = "\u{2014}";
    $dataGlyph = "'".$emDash."'";

    $directories = [
        $root.'/app',
        $root.'/bootstrap',
        $root.'/config',
        $root.'/database',
        $root.'/lang',
        $root.'/packages',
        $root.'/resources',
        $root.'/routes',
    ];

    $offenders = [];

    foreach ($directories as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.(php|md|js|css)$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $lines = explode("\n", (string) file_get_contents($file->getPathname()));

            foreach ($lines as $index => $line) {
                if (! str_contains(str_replace($dataGlyph, '', $line), $emDash)) {
                    continue;
                }

                $offenders[] = str_replace($root.'/', '', $file->getPathname()).':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Em-dashes are banned in copy, docs, and comments (.ai/guidelines/relaticle/writing.md). '.
        "Rewrite the sentence rather than swapping the character; the standalone {$dataGlyph} data glyph is allowed. ".
        'Offending lines: '.implode(', ', array_slice($offenders, 0, 40)),
    );
});

it('keeps new file uploads on medialibrary', function (): void {
    $root = dirname(__DIR__, 2);
    $allowed = [
        'app/Filament/CustomFields/RichEditorFieldType.php',
        'app/Livewire/App/Profile/UpdateProfileInformation.php',
        'packages/EmailIntegration/src/Livewire/EmailComposer.php',
        'packages/EmailIntegration/src/Services/EmailTemplateRenderService.php',
    ];
    $offenders = [];

    foreach ([$root.'/app', $root.'/packages'] as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relative = str_replace($root.'/', '', $file->getPathname());

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/\bFileUpload::make\(|->fileAttachments\(|->fileAttachmentsDisk\(|->fileAttachmentsDirectory\(/', $source) === 1) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Durable uploads go through medialibrary (.ai/rules/file-uploads.md). Offending files: '.implode(', ', $offenders),
    );
});

it('keeps runtime file access off local-only disks and paths', function (): void {
    $root = dirname(__DIR__, 2);
    $allowed = [
        'app/Console/Commands/BackfillRichEditorAttachmentsCommand.php',
        'app/Console/Commands/InstallCommand.php',
        'app/Console/Commands/LocaleDiffCommand.php',
        'app/Models/Company.php',
        'app/Models/Workspace.php',
        'app/Providers/AppServiceProvider.php',
        'app/Support/Media/RichContentAttachments.php',
        'packages/Chat/src/Actions/StoreChatAttachment.php',
        'packages/Documentation/src/Http/Controllers/OpenApiSpecController.php',
    ];
    $offenders = [];

    $directories = [$root.'/app', ...glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: []];

    foreach ($directories as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/(?<!\.blade)\.php$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relative = str_replace($root.'/', '', $file->getPathname());
            $source = (string) file_get_contents($file->getPathname());
            $permitted = in_array($relative, $allowed, true) ? 1 : 0;

            if (preg_match_all('/Storage::disk\([\'"](local|public)[\'"]\)|->useDisk\([\'"](local|public)[\'"]\)|\bpublic_path\(|\bstorage_path\(|->getRealPath\(/', $source) > $permitted) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Runtime files go through a configurable disk so Laravel Cloud replicas share them (docs/superpowers/specs/2026-09-28-laravel-cloud-readiness-design.md). Offending files: '.implode(', ', $offenders),
    );
});

it('keeps the word trait out of class files so type coverage analyses them', function (): void {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    foreach (['app', 'config', 'packages', 'routes'] as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)),
            '/(?<!\.blade)\.php$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (! str_contains($contents, 'trait ')) {
                continue;
            }

            if (preg_match('/^\s*trait\s+\w+/m', $contents) === 1) {
                continue;
            }

            $offenders[] = str_replace($root.'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe(
        [],
        'The type-coverage plugin skips every file whose text contains "trait ", comments included, so '.
        'these files are never checked. Reword the text. Offending files: '.implode(', ', $offenders),
    );
});

it('keeps the method length list to methods that still exist', function (): void {
    $root = dirname(__DIR__, 2);

    /** @var array<string, int> $grandfathered */
    $grandfathered = (require $root.'/phpstan-method-length.php')['parameters']['methodLength']['grandfathered'];

    $missing = array_values(array_filter(
        array_keys($grandfathered),
        function (string $method): bool {
            [$class, $name] = explode('::', $method);

            return ! method_exists($class, $name);
        },
    ));

    expect($missing)->toBe(
        [],
        'The method length list only shrinks (.ai/guidelines/relaticle/core.md). '.
        'Remove these entries from phpstan-method-length.php, their methods are gone: '.implode(', ', $missing),
    );
});

it('keeps the role suffix on classes whose directory carries one', function (): void {
    $root = dirname(__DIR__, 2);

    $suffixes = [
        'Console/Commands' => 'Command',
        'Http/Controllers' => 'Controller',
        'Http/Requests' => 'Request',
        'Http/Resources' => 'Resource',
        'Mail' => 'Mail',
        'Mcp/Tools' => 'Tool',
        'Observers' => 'Observer',
        'Policies' => 'Policy',
        'Queries/Filters' => 'Filter',
        'Queries/Sorts' => 'Sort',
        'Tools' => 'Tool',
    ];

    $offenders = [];

    foreach ($suffixes as $directory => $suffix) {
        $paths = array_filter(
            [$root.'/app/'.$directory, ...glob($root.'/packages/*/src/'.$directory, GLOB_ONLYDIR) ?: []],
            is_dir(...),
        );

        foreach ($paths as $path) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Concerns/')) {
                    continue;
                }

                if (str_ends_with($file->getBasename('.php'), $suffix)) {
                    continue;
                }

                $offenders[] = str_replace($root.'/', '', $file->getPathname()).' (expected *'.$suffix.')';
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'A class in these directories is named for its role (.ai/guidelines/relaticle/core.md): '.implode(', ', $offenders),
    );
});

it('names a class in a domain folder of Queries with the Query suffix', function (): void {
    $root = dirname(__DIR__, 2);

    $domainFolders = array_filter(
        glob($root.'/app/Queries/*', GLOB_ONLYDIR) ?: [],
        static fn (string $folder): bool => ! in_array(basename($folder), ['Filters', 'Sorts', 'Concerns', 'Contracts'], true),
    );

    $offenders = array_filter(
        phpFilesUnder([...$domainFolders, ...glob($root.'/packages/*/src/Queries', GLOB_ONLYDIR) ?: []]),
        static fn (string $file): bool => ! str_ends_with(basename($file, '.php'), 'Query'),
    );

    expect($offenders)->toBeEmpty(
        'A reusable read is a *Query class (.ai/rules/queries.md): '.implode(', ', $offenders),
    );
});

it('keeps reads out of the Actions folders', function (): void {
    $root = dirname(__DIR__, 2);

    $offenders = array_filter(
        phpFilesUnder([$root.'/app/Actions', ...glob($root.'/packages/*/src/Actions', GLOB_ONLYDIR) ?: []]),
        static fn (string $file): bool => preg_match('/^(List|Find|Search|Get|Aggregate)[A-Z]/', basename($file, '.php')) === 1,
    );

    expect($offenders)->toBeEmpty(
        'A reusable read is a *Query class under Queries, never an action (.ai/rules/queries.md): '.implode(', ', $offenders),
    );
});

it('guards every Queries folder against writes in phpstan.neon', function (): void {
    $root = dirname(__DIR__, 2);
    $neon = (string) file_get_contents($root.'/phpstan.neon');

    $namespaces = [
        'App\\Queries',
        ...array_map(
            static fn (string $folder): string => 'Relaticle\\'.basename(dirname($folder, 2)).'\\Queries',
            glob($root.'/packages/*/src/Queries', GLOB_ONLYDIR) ?: [],
        ),
    ];

    $unguarded = array_values(array_filter(
        $namespaces,
        static fn (string $namespace): bool => ! str_contains($neon, "- {$namespace}\n"),
    ));

    expect($unguarded)->toBe(
        [],
        'List each under guardedNamespaces of EloquentWriteOutsideActionRule (.ai/rules/queries.md): '.implode(', ', $unguarded),
    );
});

it('keeps each negated arch expectation to one layer', function (): void {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    foreach (glob($root.'/tests/Arch/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        preg_match_all('/->expect\(\s*\[(.*?)\]\s*\)(.*?);/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $layers = preg_match_all('/([\'"]).+?\1/', $match[1][0]);
            $negatesUse = preg_match('/->not\s*->\s*(toUse|toBeUsedIn)\(/', $match[2][0]) === 1;

            if ($layers < 2 || ! $negatesUse) {
                continue;
            }

            $line = substr_count($source, "\n", 0, $match[0][1]) + 1;
            $offenders[] = str_replace($root.'/', '', $file).':'.$line;
        }
    }

    expect($offenders)->toBe(
        [],
        'A negated toUse over several layers only fails when every layer violates at once '.
        '(.ai/guidelines/relaticle/testing.md). Write one arch() per layer in a foreach: '.implode(', ', $offenders),
    );
});
