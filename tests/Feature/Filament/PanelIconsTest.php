<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Support\PanelIcons;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Pages\Dashboard as SystemAdminDashboard;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Symfony\Component\DomCrawler\Crawler;

mutates(PanelIcons::class);

function shapesOf(Crawler $svg): string
{
    return implode(' ', $svg->children()->each(
        fn (Crawler $shape): string => $shape->nodeName().':'.collect(['d', 'cx', 'cy', 'r', 'x', 'y', 'width', 'height', 'rx', 'x1', 'y1', 'x2', 'y2', 'points'])
            ->map(fn (string $attribute): string => (string) $shape->attr($attribute))
            ->implode(','),
    ));
}

/** @return list<string> */
function themeSwitcherShapes(string $html): array
{
    return (new Crawler($html))->filter('.fi-theme-switcher-btn svg')->each(shapesOf(...));
}

/** @return list<string> */
function lucideShapes(string ...$icons): array
{
    return array_map(fn (string $icon): string => shapesOf((new Crawler(svg($icon)->toHtml()))->filter('svg')), $icons);
}

it('draws the app panel theme switcher with Lucide icons', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->personalWorkspace());

    $response = $this->get(Dashboard::getUrl(tenant: $user->personalWorkspace()))->assertOk();

    expect(themeSwitcherShapes((string) $response->getContent()))
        ->toBe(lucideShapes('lucide-sun', 'lucide-moon', 'lucide-monitor'));
});

it('draws the system admin panel theme switcher with Lucide icons', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');

    $response = $this->get(SystemAdminDashboard::getUrl(panel: 'sysadmin'))->assertOk();

    expect(themeSwitcherShapes((string) $response->getContent()))
        ->toBe(lucideShapes('lucide-sun', 'lucide-moon', 'lucide-monitor'));
});
