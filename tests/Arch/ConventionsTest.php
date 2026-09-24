<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
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

    foreach (glob(dirname(__DIR__, 2).'/app/Console/Commands/*.php') ?: [] as $file) {
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

it('keeps every action on the canonical single-execute() shape', function (): void {
    $root = dirname(__DIR__, 2);

    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Actions')),
        '/\.php$/',
    );

    $violations = [];

    foreach ($files as $file) {
        $path = (string) $file;

        // Fortify/Jetstream action shapes are dictated by their framework contracts.
        if (str_contains($path, '/Actions/Fortify/') || str_contains($path, '/Actions/Jetstream/')) {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], mb_substr($path, mb_strlen($root.'/app/')));

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isInterface() || $reflection->isAbstract()) {
            continue;
        }

        // An action replacing a vendor class must keep that class's shape.
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

    expect($violations)->toBe(
        [],
        'Actions expose exactly one public method, execute() (.ai/guidelines/relaticle/architecture.md). Fix: '.json_encode($violations),
    );
});

it('keeps reusable query predicates on their model as scopes', function (): void {
    $root = dirname(__DIR__, 2);

    $builders = [EloquentBuilder::class, QueryBuilder::class, EloquentBuilderContract::class, QueryBuilderContract::class];

    $allowed = [
        'Relaticle\SystemAdmin\Filament\Support\PivotSafeTableQuery::apply',
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
