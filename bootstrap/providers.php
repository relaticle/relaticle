<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FaviconServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HealthServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\MacroServiceProvider;
use Relaticle\Documentation\DocumentationServiceProvider;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\ImportWizard\ImportWizardNewServiceProvider;
use Relaticle\SystemAdmin\SystemAdminPanelProvider;

return [
    AppServiceProvider::class,
    FaviconServiceProvider::class,
    AppPanelProvider::class,
    FortifyServiceProvider::class,
    HealthServiceProvider::class,
    HorizonServiceProvider::class,
    JetstreamServiceProvider::class,
    MacroServiceProvider::class,
    DocumentationServiceProvider::class,
    EmailIntegrationServiceProvider::class,
    ImportWizardNewServiceProvider::class,
    SystemAdminPanelProvider::class,
];
