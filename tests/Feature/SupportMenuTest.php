<?php

declare(strict_types=1);

use App\Enums\SupportFormType;
use App\Features\SupportMenu;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Support\SupportForms;
use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;

mutates(SupportForms::class);
mutates(SupportFormType::class);
mutates(SupportMenu::class);

function visitDashboard(): TestResponse
{
    $user = User::factory()->withPersonalTeam()->create();
    test()->actingAs($user);
    Filament::setTenant($user->personalTeam());

    return test()->get(Dashboard::getUrl(tenant: $user->personalTeam()));
}

it('renders the help menu items for the configured support forms', function (): void {
    config([
        'relaticle.features.support_menu' => true,
        'support.forms.contact' => 'https://form.maxforms.com/relcontact',
        'support.forms.bug' => 'https://form.maxforms.com/relbug',
        'support.forms.feature' => 'https://form.maxforms.com/relfeature',
    ]);

    visitDashboard()
        ->assertOk()
        ->assertSee('Contact / Help')
        ->assertSee('Report a bug')
        ->assertSee('Suggest a feature')
        ->assertSee('form.maxforms.com/relcontact', false)
        ->assertSee('form.maxforms.com/relbug', false)
        ->assertSee('form.maxforms.com/relfeature', false);
});

it('hides the entire help menu when the support_menu feature is disabled', function (): void {
    config([
        'relaticle.features.support_menu' => false,
        'support.forms.contact' => 'https://form.maxforms.com/relcontact',
        'support.forms.bug' => 'https://form.maxforms.com/relbug',
        'support.forms.feature' => 'https://form.maxforms.com/relfeature',
    ]);

    visitDashboard()
        ->assertOk()
        ->assertDontSee('Contact / Help')
        ->assertDontSee('Report a bug')
        ->assertDontSee('Suggest a feature');
});

it('hides a help menu item whose form url is not configured', function (): void {
    config([
        'relaticle.features.support_menu' => true,
        'support.forms.contact' => 'https://form.maxforms.com/relcontact',
        'support.forms.bug' => null,
        'support.forms.feature' => null,
    ]);

    visitDashboard()
        ->assertOk()
        ->assertSee('Contact / Help')
        ->assertDontSee('Report a bug')
        ->assertDontSee('Suggest a feature');
});

it('prefills the signed-in user and team context into the form url', function (): void {
    config(['support.forms.contact' => 'https://form.maxforms.com/relcontact']);

    $user = User::factory()->withPersonalTeam()->create([
        'email' => 'jordan@example.com',
    ]);
    $this->actingAs($user);
    Filament::setTenant($user->personalTeam());

    $url = resolve(SupportForms::class)->publicUrl(SupportFormType::Contact, [
        'user_email' => (string) $user->email,
        'workspace_id' => (string) $user->personalTeam()->getKey(),
    ]);

    expect($url)
        ->toContain('https://form.maxforms.com/relcontact?')
        ->toContain('user_email='.urlencode('jordan@example.com'))
        ->toContain('workspace_id='.$user->personalTeam()->getKey());
});

it('returns null for an unconfigured support form so the item is hidden', function (): void {
    config(['support.forms.feature' => null]);

    expect(resolve(SupportForms::class)->publicUrl(SupportFormType::Feature))->toBeNull();
});
