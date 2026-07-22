<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Http\Middleware\EnsureHostedWorkspaceAccess;
use App\Models\User;
use App\Services\Billing\HostedWorkspaceAccess;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Tests\Helpers\ChatDocument;

mutates(EnsureHostedWorkspaceAccess::class, HostedWorkspaceAccess::class);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
});

it('redirects a paused hosted workspace to billing', function (): void {
    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->team->slug]))
        ->assertRedirect(route('filament.app.pages.billing', ['tenant' => $this->team->slug]));
});

it('keeps billing and workspace deletion controls available while paused', function (): void {
    $this->get(route('filament.app.pages.billing', ['tenant' => $this->team->slug]))
        ->assertOk();

    $this->get(route('filament.app.tenant.profile', ['tenant' => $this->team->slug]))
        ->assertOk();
});

it('allows a workspace during its active Cloud Pro trial', function (): void {
    $this->team->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->addDays(14),
    ])->save();

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->team->slug]))
        ->assertOk();
});

it('allows a workspace with a manual Pro grant', function (): void {
    $this->team->forceFill(['plan' => Plan::Pro])->save();

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->team->slug]))
        ->assertOk();
});

it('allows a workspace with a managed Enterprise grant', function (): void {
    $this->team->forceFill(['plan' => Plan::Enterprise])->save();

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->team->slug]))
        ->assertOk();
});

it('allows a workspace with a valid subscription', function (): void {
    $this->team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_hosted_access',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->team->slug]))
        ->assertOk();
});

it('pauses an expired trial before the daily cleanup command runs', function (): void {
    $this->team->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->subMinute(),
    ])->save();

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->team->slug]))
        ->assertRedirect(route('filament.app.pages.billing', ['tenant' => $this->team->slug]));
});

it('preserves hosted access for a grandfathered Free workspace', function (): void {
    $this->team->forceFill(['hosted_free_grandfathered_at' => now()])->save();

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->team->slug]))
        ->assertOk();
});

it('does not require hosted billing on self-hosted installations', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->team->slug]))
        ->assertOk();
});

it('returns payment required from the REST API for a paused workspace', function (): void {
    $token = $this->user->createToken('paused-api', ['*'])->plainTextToken;
    auth()->logout();

    $this->withToken($token)
        ->getJson('/api/v1/user')
        ->assertStatus(402)
        ->assertJson([
            'error' => 'workspace_subscription_required',
            'message' => __('billing.access.paused_api'),
            'upgrade_url' => route('filament.app.pages.billing', ['tenant' => $this->team->slug]),
        ]);
});

it('returns payment required from the MCP transport for a paused workspace', function (): void {
    $token = $this->user->createToken('paused-mcp', ['*'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ])
        ->assertStatus(402)
        ->assertJsonPath('error', 'workspace_subscription_required');
});

it('blocks chat before reserving credits or dispatching a queued turn', function (): void {
    Queue::fake();

    $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Create a company'),
    ])->assertStatus(402)
        ->assertJsonPath('error', 'workspace_subscription_required');

    Queue::assertNotPushed(ProcessChatMessage::class);
});
