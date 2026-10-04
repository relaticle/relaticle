<?php

declare(strict_types=1);

use App\Filament\Exports\BaseExporter;
use App\Filament\Imports\BaseImporter;
use App\Filament\Pages\Import\ImportPage;
use App\Filament\RelationManagers\BaseActivityTimelineRelationManager;
use App\Http\Requests\Api\V1\BaseCrmEntityRequest;
use App\Http\Requests\Api\V1\IndexCustomFieldsRequest;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Livewire\BaseLivewireComponent;
use App\Mcp\Tools\BaseAttachTool;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseDeleteTool;
use App\Mcp\Tools\BaseDetachTool;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\BaseRelationshipTool;
use App\Mcp\Tools\BaseShowTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Models\PersonalAccessToken;
use App\Rules\ArrayExistsForWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseMeetingsRelationManager;
use Spatie\QueryBuilder\Filters\Filter;
use Spatie\QueryBuilder\Sorts\Sort;

arch()->preset()->php();

// The strict preset is deliberately NOT enabled (evaluated 2026-06-12): its
// no-protected-methods rule fights core Laravel idioms (Model::casts(),
// provider hooks, base-tool templates), and pint already enforces final
// classes + strict types repo-wide.

arch()->preset()->security()->ignoring([
    'assert',
    'Relaticle\EmailIntegration\Jobs\StoreMeetingJob',
]);

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
        'App\ActivityLog',
        'App\Models\ActivityLog\Scopes\WorkspaceScope',
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
        BaseActivityTimelineRelationManager::class,
        BaseEmailsRelationManager::class,
        BaseMeetingsRelationManager::class,
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
        BaseRelationshipTool::class,
        BaseCrmEntityRequest::class,
        ImportPage::class,
        PersonalAccessToken::class,
    ]);

arch('ensure no extends')
    ->expect('App')
    ->classes()
    ->not
    ->toBeAbstract()
    ->ignoring([
        BaseActivityTimelineRelationManager::class,
        BaseEmailsRelationManager::class,
        BaseMeetingsRelationManager::class,
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
        BaseRelationshipTool::class,
        BaseCrmEntityRequest::class,
        ImportPage::class,
    ]);

arch('avoid mutation')
    ->expect('App')
    ->classes()
    ->toBeReadonly()
    ->ignoring([
        // Replace laravel/passkeys actions through the container; PHP forbids a
        // readonly class extending a non-readonly one.
        'App\Actions\Passkeys\DeletePasskey',
        'App\Actions\Passkeys\VerifyPasskey',
        'App\Console\Commands',
        'App\Exceptions',
        'App\Filament',
        'App\Health',
        'App\Http\Controllers\Chat',
        'App\Http\Controllers\Mcp',
        // Extends Cashier's WebhookController, its documented handler
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
        // Extends spatie's AllowedFilter; PHP forbids a readonly class extending a
        // non-readonly one.
        'App\Queries\TreeAllowedFilter',
        // Request-scoped batch_uuid holder, mutable by design (lazily caches the
        // per-request id), like a value cache rather than a service.
        'App\Support\ActivityLog\RequestActivityBatch',
        // Job-scoped holder of the running import, set and cleared by ExecuteImportJob.
        'App\Support\ActivityLog\CurrentImport',
        // Request-scoped holder of the workspace WorkspaceScope filters by, set by the tenant middleware.
        'App\Support\CurrentWorkspace',
        // Request-scoped media lookup cache, same shape: filled as list endpoints
        // prime it, reset per request via the scoped container binding.
        'App\Support\Media\MediaLookup',
        // Job-scoped custom field cache per workspace, same shape as MediaLookup.
        'App\Support\CustomFields\WorkspaceCustomFields',
        // Request/job-scoped creation-source cache, same shape as
        // RequestActivityBatch above: mutable by design, reset per request/job
        // via the scoped container binding in AppServiceProvider.
        'App\Services\WorkspaceActivationFacts',
        // Holds the actor a write names for itself, put back by the caller's finally
        // block. Same shape again: a scoped holder, not a service.
        'App\Support\LinkActorResolver',
        // Remembers which fields of an entity read the link ledger, for the lifetime of
        // one request: a lookup cache, not a service.
        'App\Support\RecordLinkFields',
        // Extends the non-readonly sluggable GenerateSlugAction to hook slug
        // uniqueness; PHP forbids a readonly class extending a non-readonly one.
        'App\Support\ReservedSlugAwareGenerateSlugAction',
        // Same shape: laravel-markdown-response resolves its detector through an
        // is_a() check against its own class, so extending it is mandatory.
        'App\Support\DetectsPublicMarkdownRequest',
        // Overrides medialibrary's DefaultPathGenerator, its documented
        // extension point; PHP forbids a readonly class extending a
        // non-readonly one.
        'App\Support\Media\UploadPathGenerator',
        // Same for DefaultUrlGenerator.
        'App\Support\Media\MediaUrlGenerator',
        // Stands in for Passport's own ClientRepository singleton; PHP forbids a
        // readonly class extending a non-readonly one.
        'App\Support\Passport\ClientRepository',
        // Extends league's BearerTokenResponse; PHP forbids a readonly class extending a non-readonly one.
        'App\Support\Passport\WorkspaceBearerTokenResponse',
        'App\View',
        'App\Services\Favicon\Drivers',
        'App\Providers\Filament',
        'App\Scribe',
        ArrayExistsForWorkspace::class,
    ]);

arch('avoid inheritance')
    ->expect('App')
    ->classes()
    ->toExtendNothing()
    ->ignoring([
        // Replace laravel/passkeys actions through the container, so they must
        // extend the class they stand in for.
        'App\Actions\Passkeys\DeletePasskey',
        'App\Actions\Passkeys\VerifyPasskey',
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
        // Subclasses spatie's AllowedFilter, the query builder's extension point.
        'App\Queries\TreeAllowedFilter',
        // Hooks slug uniqueness by extending sluggable's GenerateSlugAction,
        // which is the package's documented extension point.
        'App\Support\ReservedSlugAwareGenerateSlugAction',
        // laravel-markdown-response validates the configured detector with
        // is_a($class, DetectsMarkdownRequest::class), so it must extend it.
        'App\Support\DetectsPublicMarkdownRequest',
        // Overrides medialibrary's DefaultPathGenerator, the package's
        // documented extension point.
        'App\Support\Media\UploadPathGenerator',
        // Same for DefaultUrlGenerator.
        'App\Support\Media\MediaUrlGenerator',
        // Rebound over Passport's self-bound ClientRepository singleton, so it must
        // extend the class every OAuth endpoint type-hints.
        'App\Support\Passport\ClientRepository',
        // getExtraParams() is league's hook for extra token response fields.
        'App\Support\Passport\WorkspaceBearerTokenResponse',
    ]);

// Packages are kept final by pint (final_class, repo-wide) and strict-typed by
// the rule above. Readonly/no-inheritance is enforced only on their plain-PHP
// service layers. The rest of each package is framework-shaped (Filament,
// Livewire, Models, Tools, Jobs) and would be ignored wholesale anyway, exactly
// as the App rules above ignore those namespaces.
// (tests/Arch/ConventionsTest.php forces this list to be revisited when a
// package is added.)
$packageRoots = [];
$packageQueryLayers = [];

foreach (glob(dirname(__DIR__, 2).'/packages/*/src', GLOB_ONLYDIR) ?: [] as $packageSource) {
    $packageRoots[] = $packageRoot = 'Relaticle\\'.basename(dirname($packageSource));

    if (is_dir($packageSource.'/Queries')) {
        $packageQueryLayers[$packageRoot] = "{$packageRoot}\Queries";
    }
}

$packageServiceLayers = [
    ...array_values($packageQueryLayers),
    'Relaticle\Chat\Actions',
    'Relaticle\Chat\Agents',
    'Relaticle\Chat\Services',
    'Relaticle\Chat\Support',
    'Relaticle\Documentation\Support',
    'Relaticle\EmailIntegration\Actions',
    'Relaticle\EmailIntegration\Services',
    'Relaticle\ImportWizard\Support',
    'Relaticle\OnboardSeed\Support',
    'Relaticle\SystemAdmin\Actions',
];

arch('package service layers avoid mutation')
    ->expect($packageServiceLayers)
    ->classes()
    ->toBeReadonly()
    ->ignoring([
        // laravel/ai's Promptable trait holds mutable state; PHP forbids a
        // readonly class using a trait with a non-readonly property.
        'Relaticle\Chat\Agents',
        // Grandfathered (2026-06-12). Make each readonly, then unlist:
        'Relaticle\Chat\Services\TipTapDocumentParser',
        'Relaticle\Chat\Support\ChatTelemetry',
        'Relaticle\Chat\Support\PromptText',
        'Relaticle\Chat\Support\ProviderRateGate',
        'Relaticle\Chat\Support\TitleSanitizer',
        'Relaticle\EmailIntegration\Services\EmailVisibilityService',
        'Relaticle\EmailIntegration\Services\MailboxDisplayNameDirectory',
        'Relaticle\EmailIntegration\Services\WorkspaceMemberDirectory',
        'Relaticle\EmailIntegration\Services\MicrosoftGraphMailService',
        'Relaticle\EmailIntegration\Services\PrivacyService',
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

foreach (['App\Http', 'App\Jobs', 'App\Policies', 'App\ActivityLog', 'App\Console'] as $appLayer) {
    arch("{$appLayer} leaves email integration to its package")
        ->expect($appLayer)
        ->not
        ->toUse('Relaticle\EmailIntegration');
}

arch('email integration owns its controllers, jobs, policies and timeline entries')
    ->expect('Relaticle\EmailIntegration')
    ->not
    ->toUse(['App\Http\Controllers', 'App\Jobs', 'App\Policies', 'App\ActivityLog']);

arch('query filters implement the query builder filter contract')
    ->expect('App\Queries\Filters')
    ->toImplement(Filter::class);

arch('query sorts implement the query builder sort contract')
    ->expect('App\Queries\Sorts')
    ->toImplement(Sort::class);

arch('a query filter lives in the filters folder')
    ->expect('App\Queries')
    ->not
    ->toImplement(Filter::class)
    ->ignoring('App\Queries\Filters\\');

arch('a query sort lives in the sorts folder')
    ->expect('App\Queries')
    ->not
    ->toImplement(Sort::class)
    ->ignoring('App\Queries\Sorts\\');

arch('the query language uses no transport')
    ->expect('App\Queries')
    ->not
    ->toUse(['App\Mcp', 'App\Http', 'App\Filament', 'App\Livewire', 'App\Scribe', 'Relaticle\Chat']);

foreach ($packageQueryLayers as $packageRoot => $packageQueryLayer) {
    arch("{$packageQueryLayer} uses no transport of its package")
        ->expect($packageQueryLayer)
        ->not
        ->toUse(["{$packageRoot}\Http", "{$packageRoot}\Livewire", "{$packageRoot}\Tools", "{$packageRoot}\Jobs"]);
}

foreach (['App\Queries', ...array_values($packageQueryLayers)] as $queryRoot) {
    arch("{$queryRoot} takes the acting user and reads no ambient user, request or workspace")
        ->expect($queryRoot)
        ->not
        ->toUse([
            'auth',
            'request',
            'Illuminate\Support\Facades\Auth',
            'Illuminate\Support\Facades\Request',
            'Illuminate\Http\Request',
            'Filament\Facades\Filament',
            'App\Support\CurrentWorkspace',
        ]);
}

foreach (['App', ...$packageRoots] as $codeRoot) {
    arch("{$codeRoot} builds a list query only in a query layer")
        ->expect($codeRoot)
        ->not
        ->toUse('Spatie\QueryBuilder\QueryBuilder')
        ->ignoring(['App\Queries', ...array_values($packageQueryLayers)]);
}

arch('CRM API write requests share the custom field contract')
    ->expect('App\Http\Requests\Api\V1')
    ->classes()
    ->toExtend(BaseCrmEntityRequest::class)
    ->ignoring([
        BaseCrmEntityRequest::class,
        IndexCustomFieldsRequest::class,
        IndexRequest::class,
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
        'App\Queries',
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

foreach (['App\Filament', 'App\Livewire', 'Relaticle\Chat\Livewire', 'Relaticle\Chat\Tools'] as $uiLayer) {
    arch("{$uiLayer} must not use the DB facade directly")
        ->expect($uiLayer)
        ->not
        ->toUse([
            'Illuminate\Support\Facades\DB',
        ])
        ->ignoring([
            // Grandfathered (2026-06-12). Move these writes into actions, then unlist:
            'App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard',
            'App\Filament\Resources\TaskResource\Pages\TasksBoard',
            'App\Livewire\App\AccessTokens\CreateAccessToken',
            // Session-table infrastructure (no Eloquent model), a legitimate DB facade use:
            'App\Livewire\App\Profile\LogoutOtherBrowserSessions',
            // Slipped past while one check spanned all four layers and could not fail
            // (found 2026-10-04). Review each, then unlist:
            'App\Filament\Pages\Workspace\ActivityLog',
            'App\Livewire\App\AccessTokens\ManageOAuthConnectors',
            'App\Livewire\App\Profile\ManageMfa',
            'App\Livewire\App\Workspaces\WorkspaceMembers',
            // One recursive CTE walks the link ledger; no Eloquent relation expresses it.
            'Relaticle\Chat\Tools\GetRelatedRecordsTool',
        ]);
}

foreach (['App', 'Relaticle\ImportWizard', 'Relaticle\OnboardSeed', 'Relaticle\Documentation'] as $layer) {
    arch("{$layer} must not use custom-fields package models directly")
        ->expect($layer)
        ->not
        ->toUse([
            'Relaticle\CustomFields\Models\CustomField',
            'Relaticle\CustomFields\Models\CustomFieldLink',
            'Relaticle\CustomFields\Models\CustomFieldOption',
            'Relaticle\CustomFields\Models\CustomFieldRelationship',
            'Relaticle\CustomFields\Models\CustomFieldSection',
            'Relaticle\CustomFields\Models\CustomFieldValue',
        ])
        ->ignoring([
            'App\Models\CustomField',
            'App\Models\CustomFieldLink',
            'App\Models\CustomFieldOption',
            'App\Models\CustomFieldRelationship',
            'App\Models\CustomFieldSection',
            'App\Models\CustomFieldValue',
            'App\Filament\CustomFields\DomainFieldType',
            // Slipped past while one check spanned all four layers and could not fail
            // (found 2026-10-04). Review each, then unlist:
            'App\Filament\CustomFields\DateTimeColumn',
            'App\Filament\CustomFields\DateTimeEntry',
            'App\Filament\CustomFields\RichContentEntry',
            'App\Filament\CustomFields\RichEditorFieldType',
            'App\Http\Resources\V1\Concerns\FormatsCustomFields',
            'App\Listeners\CustomFields\LogLinkChangeListener',
            'App\Mcp\Schema\CustomFieldSchema',
            'App\Observers\CustomFieldValueObserver',
            'App\Rules\OwnedLinkTargets',
            'App\Rules\ValidCustomFields',
            'App\Support\CustomFields\CustomFieldInput',
            'App\Support\RecordLinkFields',
            'App\Support\ActivityLog\CustomFieldChangeLog',
            'App\Support\CustomFieldMerger',
            'App\Support\Media\UploadClaims',
            'Relaticle\ImportWizard\Data\EntityLink',
            'Relaticle\ImportWizard\Importers\BaseImporter',
            'Relaticle\ImportWizard\Jobs\ExecuteImportJob',
            'Relaticle\ImportWizard\Support\DataTypeInferencer',
            'Relaticle\OnboardSeed\Support\BulkCustomFieldValueWriter',
        ]);
}

// Livewire hands every client-invoked method through implicit route-model binding
// (Wrapped::__call -> ImplicitlyBoundMethod), and Eloquent's resolveRouteBinding is
// a bare where(key)->first(). A public method typed against a model therefore reads
// whatever id the browser sends, ignoring workspace, owner and status. That is the shape
// let ProposalCard's dock reads return another tenant's proposal. Take the id as a
// string and resolve it through a scoped query instead.
//
// Lifecycle methods are exempt: Livewire's SupportLifecycleHooks throws
// DirectlyCallingLifecycleHooksNotAllowedException before the call allowlist runs,
// so mount() and friends are not client-callable.
it('keeps Eloquent models off the client-callable surface of Livewire components', function (): void {
    // Verified safe (2026-08-25): each passes the client-supplied workspace straight to an
    // action that authorizes the ACTING user against THAT workspace, and returns void.
    $grandfathered = [
        'App\Livewire\App\Workspaces\DeleteWorkspace::cancelWorkspaceDeletion',
        'App\Livewire\App\Workspaces\DeleteWorkspace::deleteWorkspace',
        'App\Livewire\App\Workspaces\WorkspaceMembers::leaveWorkspace',
        'App\Livewire\App\Workspaces\WorkspaceMembers::removeWorkspaceMember',
        'App\Livewire\App\Workspaces\WorkspaceMembers::updateWorkspaceRole',
        'App\Livewire\App\Workspaces\UpdateWorkspaceName::updateWorkspaceName',
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

arch('reaches the network, the shell and the wait through wrappers a test can fake')
    ->expect([
        'curl_exec',
        'curl_init',
        'sleep',
        'usleep',
        'GuzzleHttp\Client',
        'Symfony\Component\HttpClient\HttpClient',
        'Symfony\Component\Process\Process',
    ])
    ->not->toBeUsed();
