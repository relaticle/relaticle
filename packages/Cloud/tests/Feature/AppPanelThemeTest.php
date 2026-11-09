<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Relaticle\Cloud\CloudServiceProvider;

mutates(CloudServiceProvider::class);

beforeEach(function (): void {
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString('<link rel="stylesheet" data-entrypoint="'.implode(',', (array) $entrypoints).'">');
        }

        public function __call($method, $parameters): string
        {
            return '';
        }
    });
});

it('loads the cloud theme instead of the core theme on the app panel', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user)
        ->get(Dashboard::getUrl(panel: 'app', tenant: $user->currentWorkspace))
        ->assertOk()
        ->assertSee('data-entrypoint="packages/Cloud/resources/css/filament/app/theme.css"', false)
        ->assertDontSee('data-entrypoint="resources/css/filament/app/theme.css"', false);
});

it('loads the cloud theme instead of the core theme on the sysadmin panel', function (): void {
    $this->get(route('filament.sysadmin.auth.login'))
        ->assertOk()
        ->assertSee('data-entrypoint="packages/Cloud/resources/css/filament/admin/theme.css"', false)
        ->assertDontSee('data-entrypoint="resources/css/filament/admin/theme.css"', false)
        ->assertDontSee('packages/Cloud/resources/css/filament/app/theme.css', false);
});
