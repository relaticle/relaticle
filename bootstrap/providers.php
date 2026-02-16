<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\FaviconServiceProvider::class,
    App\Providers\Filament\AppPanelProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\HealthServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\JetstreamServiceProvider::class,
    App\Providers\MacroServiceProvider::class,
    Relaticle\Documentation\DocumentationServiceProvider::class,
    Relaticle\ImportWizard\ImportWizardNewServiceProvider::class,
    Relaticle\SystemAdmin\SystemAdminPanelProvider::class,
];
