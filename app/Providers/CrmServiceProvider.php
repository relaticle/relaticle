<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\RedirectPublicPagesToApp;
use App\Models\User;
use App\Support\Crm\SignupGate;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Everything that turns the Relaticle fork into Crabdev's internal CRM, kept in
 * our own files so `git pull upstream main` stays conflict-free: branding
 * (name, logo, colors, favicon, mail header), invitation-only signup and no
 * public marketing site. Driven by config/crm.php.
 */
final class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        User::creating(function (User $user): void {
            resolve(SignupGate::class)->ensureAllowed($user);
        });

        // The bound (singleton) kernel, so the change reaches the router it serves.
        $this->app->make(HttpKernel::class)->appendMiddlewareToGroup('web', RedirectPublicPagesToApp::class);

        if (config('crm.brand.enabled')) {
            $this->applyBranding();
        }
    }

    public function applyBranding(): void
    {
        $name = (string) config('crm.brand.name');
        $palette = Color::hex((string) config('crm.brand.color'));

        config([
            'app.name' => $name,
            'relaticle.company.name' => (string) config('crm.brand.company'),
            'mail.markdown.paths' => [resource_path('views/crm/mail'), ...(array) config('mail.markdown.paths', [])],
        ]);

        // Our views in resources/views/crm win over upstream's files of the same name.
        View::prependLocation(resource_path('views/crm'));

        Filament::getPanel('app')
            ->brandName($name)
            ->favicon(asset('crm/favicon.svg'))
            ->colors(['primary' => $palette]);

        FilamentColor::register(['primary' => $palette]);

        // The purple ramp is baked into resources/css/theme.css and components read
        // `--color-primary-*`, so the panel colors alone change nothing. An unlayered
        // :root rule outranks the Tailwind theme layer; a user-picked accent
        // (`html[data-accent]`) is more specific and still wins.
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn (): string => $this->primaryColorStyles($palette),
        );
    }

    /**
     * @param  array<int, string>  $palette
     */
    private function primaryColorStyles(array $palette): string
    {
        $tokens = collect($palette)
            ->map(fn (string $value, int $shade): string => "--primary-{$shade}: {$value}; --color-primary-{$shade}: {$value};")
            ->implode(' ');

        return "<style>:root { {$tokens} --color-primary: {$palette[600]}; }</style>";
    }
}
