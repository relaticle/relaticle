<?php

declare(strict_types=1);

return [
    App\Providers\MacroServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    Relaticle\SystemAdmin\SystemAdminPanelProvider::class,
    App\Providers\Filament\AppPanelProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\JetstreamServiceProvider::class,
    Relaticle\Documentation\DocumentationServiceProvider::class,
    Relaticle\ImportWizard\ImportWizardNewServiceProvider::class,
    App\Providers\FaviconServiceProvider::class,
];
