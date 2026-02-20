<?php

declare(strict_types=1);

use App\Filament\Exports\BaseExporter;
use App\Filament\Imports\BaseImporter;
use App\Filament\Pages\Import\ImportPage;
use App\Livewire\BaseLivewireComponent;

arch()->preset()->php();

// arch()->preset()->strict();

arch()->preset()->security()->ignoring('assert');

arch()->preset()
    ->laravel()
    ->ignoring([
        'App\Providers\AppServiceProvider',
        'App\Providers\Filament\AppPanelProvider',
        'Relaticle\Admin\AdminPanelProvider',
        'App\Enums\EnumValues',
        'App\Enums\CustomFields\CustomFieldTrait',
    ]);

arch('strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('avoid open for extension')
    ->expect('App')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        BaseLivewireComponent::class,
        BaseImporter::class,
        BaseExporter::class,
        ImportPage::class,
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
        'App\Http\Requests',
        'App\Jobs',
        'App\Listeners',
        'App\Livewire',
        'App\Mail',
        'App\Models',
        'App\Data',
        'App\Notifications',
        'App\Providers',
        'App\View',
        'App\Services\Favicon\Drivers',
        'App\Providers\Filament',
    ]);

arch('avoid inheritance')
    ->expect('App')
    ->classes()
    ->toExtendNothing()
    ->ignoring([
        'App\Console\Commands',
        'App\Exceptions',
        'App\Filament',
        'App\Http\Requests',
        'App\Jobs',
        'App\Data',
        'App\Livewire',
        'App\Mail',
        'App\Health',
        'App\Models',
        'App\Notifications',
        'App\Providers',
        'App\View',
    ]);

// arch('annotations')
//    ->expect('App')
//    ->toHavePropertiesDocumented()
//    ->toHaveMethodsDocumented();

arch('main app must not depend on SystemAdmin module')
    ->expect('App')
    ->not
    ->toUse('Relaticle\SystemAdmin')
    ->ignoring([
        'App\Providers\AppServiceProvider',
        'App\Console\Commands\InstallCommand',
        'App\Console\Commands\CreateSystemAdminCommand',
    ]);

arch('SystemAdmin module must not depend on main app namespace')
    ->expect('Relaticle\SystemAdmin')
    ->not
    ->toUse('App')
    ->ignoring([
        'App\Models',
        'App\Enums',
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
