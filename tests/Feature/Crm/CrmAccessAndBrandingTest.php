<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\RedirectPublicPagesToApp;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Providers\CrmServiceProvider;
use App\Support\Crm\SignupGate;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Validation\ValidationException;

mutates(CrmServiceProvider::class, RedirectPublicPagesToApp::class, SignupGate::class);

describe('public site', function (): void {
    it('sends visitors of the public site to the app when it is off', function (string $path): void {
        config()->set('crm.access.public_pages', false);

        $this->get($path)->assertRedirect(url()->getAppUrl());
    })->with(['/', '/pricing', '/terms-of-service', '/contact']);

    it('leaves the login page alone', function (): void {
        config()->set('crm.access.public_pages', false);

        $this->get(url()->getAppUrl('login'))->assertOk();
    });

    it('serves the public site when it is on', function (): void {
        config()->set('crm.access.public_pages', true);

        $this->get('/pricing')->assertOk();
    });
});

describe('invitation-only signup', function (): void {
    it('refuses to create an account without an invitation', function (): void {
        config()->set('crm.access.open_signup', false);
        $email = 'sin-invitacion-'.uniqid().'@gmail.com';

        livewire(Login::class)
            ->fillForm(['email' => $email])
            ->call('authenticate')
            ->assertSet('authMethod', 'signup')
            ->fillForm(['password' => 'Password123!'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        expect(User::query()->where('email', $email)->exists())->toBeFalse();
    });

    it('creates the account of someone with a pending invitation', function (): void {
        $invitation = TeamInvitation::factory()->create(['email' => 'invitado-'.uniqid().'@gmail.com']);
        config()->set('crm.access.open_signup', false);

        livewire(Login::class)
            ->fillForm(['email' => $invitation->email])
            ->call('authenticate')
            ->fillForm(['password' => 'Password123!'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        expect(User::query()->where('email', $invitation->email)->exists())->toBeTrue();
    });

    it('does not accept an expired invitation', function (): void {
        $invitation = TeamInvitation::factory()->expired()->create(['email' => 'caducado-'.uniqid().'@gmail.com']);
        config()->set('crm.access.open_signup', false);

        expect(fn () => User::factory()->create(['email' => $invitation->email]))
            ->toThrow(ValidationException::class);
    });

    it('lets anyone sign up when signup is open', function (): void {
        config()->set('crm.access.open_signup', true);

        expect(User::factory()->create())->toBeInstanceOf(User::class);
    });
});

describe('branding', function (): void {
    beforeEach(function (): void {
        app()->getProvider(CrmServiceProvider::class)->applyBranding();
    });

    it('names the app and the panel after Crabdev', function (): void {
        $panel = Filament::getPanel('app');

        expect(config('app.name'))->toBe('Crabdev CRM')
            ->and($panel->getBrandName())->toBe('Crabdev CRM')
            ->and($panel->getFavicon())->toEndWith('crm/favicon.svg');
    });

    it('shows the Crabdev logo and colors on the login page, without Relaticle links', function (): void {
        $this->get(url()->getAppUrl('login'))
            ->assertOk()
            ->assertSee('Crabdev')
            ->assertSee('--color-primary-600', false)
            ->assertDontSee('/terms-of-service', false);
    });

    it('puts the Crabdev name in the mail header', function (): void {
        $html = (string) new MailMessage()->line('Hola')->render();

        expect($html)->toContain('Crabdev')
            ->not->toContain('alt="Relaticle"');
    });
});
