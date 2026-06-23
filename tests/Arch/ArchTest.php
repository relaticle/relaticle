<?php

declare(strict_types=1);

use App\Filament\Exports\BaseExporter;
use App\Filament\Imports\BaseImporter;
use App\Filament\Pages\Import\ImportPage;
use App\Filament\RelationManagers\BaseActivityTimelineRelationManager;
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
use Relaticle\EmailIntegration\Filament\Pages\BaseRecordEmailsPage;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseMeetingsRelationManager;

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
        'App\ActivityLog',
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
        BaseActivityTimelineRelationManager::class,
        BaseEmailsRelationManager::class,
        BaseMeetingsRelationManager::class,
        BaseRecordEmailsPage::class,
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
        BaseActivityTimelineRelationManager::class,
        BaseEmailsRelationManager::class,
        BaseMeetingsRelationManager::class,
        BaseRecordEmailsPage::class,
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
        BaseRecordEmailsPage::class,
    ]);

arch('avoid mutation')
    ->expect('App')
    ->classes()
    ->toBeReadonly()
    ->ignoring([
        'App\Ai',
        'App\Console\Commands',
        'App\Exceptions',
        'App\Filament',
        'App\Health',
        'App\Http\Controllers\Chat',
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
        'App\Ai',
        'App\Console\Commands',
        'App\Exceptions',
        'App\Filament',
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
    'Relaticle\Documentation\Services',
    'Relaticle\EmailIntegration\Actions',
    'Relaticle\EmailIntegration\Services',
    'Relaticle\ImportWizard\Support',
    'Relaticle\OnboardSeed\Support',
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
        'Relaticle\Documentation\Services\DocumentationService',
        // Legitimate per-instance memoization caches — intentionally mutable, not tech debt:
        'Relaticle\EmailIntegration\Services\MicrosoftGraphMailService',
        'Relaticle\EmailIntegration\Services\PrivacyService',
        // Exceptions necessarily extend a base throwable:
        'Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired',
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
    ->toExtendNothing()
    ->ignoring([
        // Exceptions necessarily extend a base throwable:
        'Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired',
    ]);

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
