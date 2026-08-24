<?php

declare(strict_types=1);

use App\Filament\Exports\BaseExporter;
use App\Filament\Imports\BaseImporter;
use App\Filament\Pages\Import\ImportPage;
use App\Livewire\BaseLivewireComponent;
use App\Mcp\Tools\BaseAttachTool;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseDeleteTool;
use App\Mcp\Tools\BaseDetachTool;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\BaseShowTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Models\PersonalAccessToken;
use App\Rules\ArrayExistsForTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;

arch()->preset()->php();

// The strict preset is deliberately NOT enabled (evaluated 2026-06-12): its
// no-protected-methods rule fights core Laravel idioms (Model::casts(),
// provider hooks, base-tool templates), and pint already enforces final
// classes + strict types repo-wide.

arch()->preset()->security()->ignoring('assert');

arch()->preset()
    ->laravel()
    ->ignoring([
        'App\Providers\AppServiceProvider',
        'App\Providers\Filament\AppPanelProvider',
        'Relaticle\Admin\AdminPanelProvider',
        'App\Enums\EnumValues',
        'App\Enums\CustomFields\CustomFieldTrait',
        'App\Mcp',
        'App\Http\Controllers\Mcp',
        'App\Models\ActivityLog\Scopes\TeamScope',
        // Chat tools intentionally reuse App\Http\Resources (consistent
        // LLM-facing payloads); the preset forbids resources outside Http.
        'Relaticle\Chat',
    ]);

arch('strict types')
    ->expect(['App', 'Relaticle'])
    ->toUseStrictTypes();

arch('avoid open for extension')
    ->expect('App')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        BaseLivewireComponent::class,
        BaseImporter::class,
        BaseExporter::class,
        BaseListTool::class,
        BaseShowTool::class,
        BaseCreateTool::class,
        BaseUpdateTool::class,
        BaseDeleteTool::class,
        BaseAttachTool::class,
        BaseDetachTool::class,
        ImportPage::class,
        PersonalAccessToken::class,
    ]);

arch('ensure no extends')
    ->expect('App')
    ->classes()
    ->not
    ->toBeAbstract()
    ->ignoring([
        BaseLivewireComponent::class,
        BaseImporter::class,
        BaseExporter::class,
        BaseListTool::class,
        BaseShowTool::class,
        BaseCreateTool::class,
        BaseUpdateTool::class,
        BaseDeleteTool::class,
        BaseAttachTool::class,
        BaseDetachTool::class,
        ImportPage::class,
    ]);

arch('avoid mutation')
    ->expect('App')
    ->classes()
    ->toBeReadonly()
    ->ignoring([
        'App\Console\Commands',
        'App\Exceptions',
        'App\Filament',
        'App\Health',
        'App\Http\Controllers\Chat',
        'App\Http\Controllers\Mcp',
        // Extends Cashier's WebhookController — its documented handler
        // extension point; PHP forbids a readonly class extending a
        // non-readonly one.
        'App\Http\Controllers\Billing\StripeWebhookController',
        'App\Http\Requests',
        'App\Http\Resources',
        'App\Jobs',
        'App\Listeners',
        'App\Livewire',
        'App\Mail',
        'App\Mcp',
        'App\Models',
        'App\Observers',
        'App\Data',
        'App\Notifications',
        'App\Providers',
        'App\Support\ActivityLog\CleanActivityLogAction',
        // Request-scoped batch_uuid holder — mutable by design (lazily caches the
        // per-request id), like a value cache rather than a service.
        'App\Support\ActivityLog\RequestActivityBatch',
        // Extends the non-readonly sluggable GenerateSlugAction to hook slug
        // uniqueness; PHP forbids a readonly class extending a non-readonly one.
        'App\Support\ReservedSlugAwareGenerateSlugAction',
        // Same shape: laravel-markdown-response resolves its detector through an
        // is_a() check against its own class, so extending it is mandatory.
        'App\Support\DetectsPublicMarkdownRequest',
        'App\View',
        'App\Services\Favicon\Drivers',
        'App\Providers\Filament',
        'App\Scribe',
        ArrayExistsForTeam::class,
    ]);

arch('avoid inheritance')
    ->expect('App')
    ->classes()
    ->toExtendNothing()
    ->ignoring([
        'App\Console\Commands',
        'App\Exceptions',
        'App\Filament',
        'App\Http\Controllers\Mcp',
        // Overrides Cashier's subscription-created handler so an abandoned
        // checkout does not consume the workspace's generic trial.
        'App\Http\Controllers\Billing\StripeWebhookController',
        'App\Http\Requests',
        'App\Http\Resources',
        'App\Jobs',
        'App\Data',
        'App\Livewire',
        'App\Mail',
        'App\Health',
        'App\Mcp',
        'App\Models',
        'App\Notifications',
        'App\Providers',
        'App\Scribe',
        'App\View',
        'App\Support\ActivityLog\CleanActivityLogAction',
        // Hooks slug uniqueness by extending sluggable's GenerateSlugAction,
        // which is the package's documented extension point.
        'App\Support\ReservedSlugAwareGenerateSlugAction',
        // laravel-markdown-response validates the configured detector with
        // is_a($class, DetectsMarkdownRequest::class), so it must extend it.
        'App\Support\DetectsPublicMarkdownRequest',
    ]);

// Packages are kept final by pint (final_class, repo-wide) and strict-typed by
// the rule above. Readonly/no-inheritance is enforced only on their plain-PHP
// service layers — the rest of each package is framework-shaped (Filament,
// Livewire, Models, Tools, Jobs) and would be ignored wholesale anyway, exactly
// as the App rules above ignore those namespaces.
// (tests/Arch/ConventionsTest.php forces this list to be revisited when a
// package is added.)
$packageServiceLayers = [
    'Relaticle\Chat\Actions',
    'Relaticle\Chat\Agents',
    'Relaticle\Chat\Services',
    'Relaticle\Chat\Support',
    'Relaticle\Documentation\Support',
    'Relaticle\ImportWizard\Support',
    'Relaticle\OnboardSeed\Support',
    'Relaticle\SystemAdmin\Actions',
];

arch('package service layers avoid mutation')
    ->expect($packageServiceLayers)
    ->classes()
    ->toBeReadonly()
    ->ignoring([
        // Grandfathered (2026-06-12) — make each readonly, then unlist:
        'Relaticle\Chat\Agents\CrmAssistant',
        'Relaticle\Chat\Services\TipTapDocumentParser',
        'Relaticle\Chat\Support\ChatTelemetry',
        'Relaticle\Chat\Support\LikePattern',
        'Relaticle\Chat\Support\PromptText',
        'Relaticle\Chat\Support\ProviderRateGate',
        'Relaticle\Chat\Support\TitleSanitizer',
        'Relaticle\ImportWizard\Support\DataTypeInferencer',
        'Relaticle\ImportWizard\Support\EntityLinkResolver',
        'Relaticle\ImportWizard\Support\EntityLinkStorage\CustomFieldValueStorage',
        'Relaticle\ImportWizard\Support\EntityLinkStorage\ForeignKeyStorage',
        'Relaticle\ImportWizard\Support\EntityLinkStorage\MorphToManyStorage',
        'Relaticle\ImportWizard\Support\EntityLinkValidator',
        'Relaticle\ImportWizard\Support\Validation\ColumnValidator',
        'Relaticle\OnboardSeed\Support\BaseModelSeeder',
        'Relaticle\OnboardSeed\Support\BulkCustomFieldValueWriter',
        'Relaticle\OnboardSeed\Support\FixtureLoader',
        'Relaticle\OnboardSeed\Support\FixtureRegistry',
    ]);

arch('package service layers avoid inheritance')
    ->expect($packageServiceLayers)
    ->classes()
    ->toExtendNothing();

arch('main app must not depend on SystemAdmin module')
    ->expect('App')
    ->not
    ->toUse('Relaticle\SystemAdmin')
    ->ignoring([
        'App\Providers\AppServiceProvider',
        'App\Console\Commands\InstallCommand',
        'App\Console\Commands\CreateSystemAdminCommand',
        'App\Console\Commands\MakeFilamentUserCommand',
    ]);

arch('SystemAdmin module must not depend on main app namespace')
    ->expect('Relaticle\SystemAdmin')
    ->not
    ->toUse('App')
    ->ignoring([
        'App\Models',
        'App\Enums',
        'App\Rules',
    ]);

arch('API controllers must not use Eloquent query methods directly')
    ->expect('App\Http\Controllers\Api\V1')
    ->not
    ->toUse([
        'Illuminate\Support\Facades\DB',
    ]);

arch('API controllers must depend on actions for write operations')
    ->expect('App\Http\Controllers\Api\V1')
    ->toOnlyUse([
        'App\Actions',
        'App\Enums',
        'App\Http\Requests',
        'App\Http\Resources',
        'App\Models',
        'Illuminate',
        'Knuckles\Scribe',
        'response',
    ]);

arch('MCP tools must not use DB facade directly')
    ->expect('App\Mcp\Tools')
    ->not
    ->toUse([
        'Illuminate\Support\Facades\DB',
    ]);

arch('UI surfaces must not use the DB facade directly')
    ->expect([
        'App\Filament',
        'App\Livewire',
        'Relaticle\Chat\Livewire',
        'Relaticle\Chat\Tools',
    ])
    ->not
    ->toUse([
        'Illuminate\Support\Facades\DB',
    ])
    ->ignoring([
        // Grandfathered (2026-06-12) — move these writes into actions, then unlist:
        'App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard',
        'App\Filament\Resources\TaskResource\Pages\TasksBoard',
        'App\Livewire\App\AccessTokens\CreateAccessToken',
        // Session-table infrastructure (no Eloquent model) — legitimate DB facade use:
        'App\Livewire\App\Profile\LogoutOtherBrowserSessions',
        // Read-only aggregate join for stream recovery:
        'Relaticle\Chat\Livewire\Chat\ChatInterface',
    ]);

arch('must not use custom-fields package models directly')
    ->expect([
        'App',
        'Relaticle\ImportWizard',
        'Relaticle\OnboardSeed',
        'Relaticle\Documentation',
    ])
    ->not
    ->toUse([
        'Relaticle\CustomFields\Models\CustomField',
        'Relaticle\CustomFields\Models\CustomFieldOption',
        'Relaticle\CustomFields\Models\CustomFieldSection',
        'Relaticle\CustomFields\Models\CustomFieldValue',
    ])
    ->ignoring([
        'App\Models\CustomField',
        'App\Models\CustomFieldOption',
        'App\Models\CustomFieldSection',
        'App\Models\CustomFieldValue',
    ]);

// Livewire hands every client-invoked method through implicit route-model binding
// (Wrapped::__call -> ImplicitlyBoundMethod), and Eloquent's resolveRouteBinding is
// a bare where(key)->first(). A public method typed against a model therefore reads
// whatever id the browser sends, ignoring team, owner and status. That is the shape
// let ProposalCard's dock reads return another tenant's proposal. Take the id as a
// string and resolve it through a scoped query instead.
//
// Lifecycle methods are exempt: Livewire's SupportLifecycleHooks throws
// DirectlyCallingLifecycleHooksNotAllowedException before the call allowlist runs,
// so mount() and friends are not client-callable.
it('keeps Eloquent models off the client-callable surface of Livewire components', function (): void {
    // Verified safe (2026-08-25): each passes the client-supplied team straight to an
    // action that authorizes the ACTING user against THAT team, and returns void.
    $grandfathered = [
        'App\Livewire\App\Teams\AddTeamMember::addTeamMember',
        'App\Livewire\App\Teams\DeleteTeam::cancelTeamDeletion',
        'App\Livewire\App\Teams\DeleteTeam::deleteTeam',
        'App\Livewire\App\Teams\TeamMembers::leaveTeam',
        'App\Livewire\App\Teams\TeamMembers::removeTeamMember',
        'App\Livewire\App\Teams\TeamMembers::updateTeamRole',
        'App\Livewire\App\Teams\UpdateTeamName::updateTeamName',
    ];

    $lifecycle = ['mount', 'boot', 'booted', 'exception', 'rendering', 'rendered', 'scriptSrc', 'hydrate', 'dehydrate', 'updating', 'updated', 'render'];

    // The Arch suite runs without a booted application, so base_path() is unavailable.
    $root = dirname(__DIR__, 2);

    $roots = [[$root.'/app', 'App\\']];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $src) {
        $roots[] = [$src, 'Relaticle\\'.basename(dirname($src)).'\\'];
    }

    $offenders = [];

    foreach ($roots as [$dir, $namespace]) {
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($dir) + 1));

            if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class !== $class || $method->isStatic()) {
                    continue;
                }

                if (Str::startsWith($method->getName(), $lifecycle)) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();

                    if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                        continue;
                    }

                    if (! is_subclass_of($type->getName(), Model::class)) {
                        continue;
                    }

                    $signature = $class.'::'.$method->getName();

                    if (in_array($signature, $grandfathered, true)) {
                        continue;
                    }

                    $offenders[] = $signature.'('.class_basename($type->getName()).' $'.$parameter->getName().')';
                }
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
