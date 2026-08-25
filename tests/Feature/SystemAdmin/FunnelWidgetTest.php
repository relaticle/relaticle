<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\TeamRole;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Filament\Widgets\FunnelWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(FunnelWidget::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('can render the funnel widget', function (): void {
    livewire(FunnelWidget::class)
        ->assertOk();
});

it('counts an organic team owner as a sign-up', function (): void {
    User::factory()->withTeam()->create([
        'created_at' => now()->subDays(5),
    ]);

    livewire(FunnelWidget::class)
        ->assertSee('Organic Sign-ups')
        ->assertSee('1');
});

it('excludes an invited member attached to another team within 24h of registering', function (): void {
    $owner = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(10),
    ]);

    $invited = User::factory()->create([
        'created_at' => now()->subDays(5),
    ]);

    DB::table('team_user')->insert([
        'team_id' => $owner->currentTeam->id,
        'user_id' => $invited->id,
        'role' => TeamRole::Editor->value,
        'created_at' => $invited->created_at->addMinutes(3),
        'updated_at' => $invited->created_at->addMinutes(3),
    ]);

    livewire(FunnelWidget::class)
        ->assertSee('Organic Sign-ups')
        ->assertSee('1');
});

it('still counts a user joining another team long after registering as organic', function (): void {
    $owner = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(10),
    ]);

    $laterJoiner = User::factory()->create([
        'created_at' => now()->subDays(5),
    ]);

    DB::table('team_user')->insert([
        'team_id' => $owner->currentTeam->id,
        'user_id' => $laterJoiner->id,
        'role' => TeamRole::Editor->value,
        'created_at' => $laterJoiner->created_at->addDays(2),
        'updated_at' => $laterJoiner->created_at->addDays(2),
    ]);

    livewire(FunnelWidget::class)
        ->assertSee('Organic Sign-ups')
        ->assertSee('2');
});

it('counts a team as activated once a non-system record is created within the period', function (): void {
    $owner = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(10),
    ]);
    $team = $owner->currentTeam;

    Company::withoutEvents(fn () => Company::factory()
        ->for($team)
        ->create([
            'creator_id' => $owner->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(4),
        ]));

    livewire(FunnelWidget::class)
        ->assertSee('Activated Teams')
        ->assertSee('1');
});

it('excludes system-created records from the activated teams count', function (): void {
    $owner = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(10),
    ]);
    $team = $owner->currentTeam;

    Company::withoutEvents(fn () => Company::factory()
        ->for($team)
        ->create([
            'creator_id' => $owner->id,
            'creation_source' => CreationSource::SYSTEM,
            'created_at' => now()->subDays(4),
        ]));

    livewire(FunnelWidget::class)
        ->assertSee('Activated Teams')
        ->assertSee('0');
});

it('counts a team as subscribed once it holds an active subscription created within the period', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_funnel_active',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'created_at' => now()->subDays(2),
    ]);

    livewire(FunnelWidget::class)
        ->assertSee('Subscribed Teams')
        ->assertSee('1');
});

it('excludes an unpaid subscription from the subscribed teams count', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_funnel_unpaid',
        'stripe_status' => 'unpaid',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'created_at' => now()->subDays(2),
    ]);

    livewire(FunnelWidget::class)
        ->assertSee('Subscribed Teams')
        ->assertSee('0');
});

it('shows stage conversion rates when the denominators are non-zero', function (): void {
    $activatedOwner = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(10),
    ]);

    User::factory()->withTeam()->create([
        'created_at' => now()->subDays(8),
    ]);

    Company::withoutEvents(fn () => Company::factory()
        ->for($activatedOwner->currentTeam)
        ->create([
            'creator_id' => $activatedOwner->id,
            'account_owner_id' => $activatedOwner->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(4),
        ]));

    $activatedOwner->currentTeam->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_funnel_rates',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'created_at' => now()->subDays(2),
    ]);

    livewire(FunnelWidget::class)
        ->assertSee('50% of sign-ups')
        ->assertSee('100% of activated');
});
