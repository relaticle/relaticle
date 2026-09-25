<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\AiCreditBalance;

beforeEach(function (): void {
    Queue::fake();
});

it('returns plan-aware copy when a Free user is out of credits', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Free->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    $response->assertJsonStructure([
        'error',
        'message',
        'plan',
        'allowance',
        'reset_at',
        'upgrade_available',
    ]);
    expect($response->json('error'))->toBe('credits_exhausted');
    expect($response->json('plan'))->toBe('free');
    expect($response->json('allowance'))->toBe(Plan::Free->credits());
    expect($response->json('upgrade_available'))->toBeTrue();
});

it('marks upgrade_available false for Pro users', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->plan = Plan::Pro;
    $workspace->save();

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Pro->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('plan'))->toBe('pro');
    expect($response->json('allowance'))->toBe(Plan::Pro->credits());
    expect($response->json('upgrade_available'))->toBeFalse();
});

it('marks upgrade_available false for Enterprise users', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->plan = Plan::Enterprise;
    $workspace->save();

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Enterprise->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('plan'))->toBe('enterprise');
    expect($response->json('upgrade_available'))->toBeFalse();
});

it('mentions the plan name in the message', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Free->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('message'))->toContain('Free');
    expect($response->json('message'))->toContain((string) Plan::Free->credits());
});

it('offers a top-up url to exhausted paid plans when billing is active', function (): void {
    Feature::define(Billing::class, true);
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->plan = Plan::Pro;
    $workspace->save();

    // A valid Cashier subscription is how a real paying customer reaches this
    // plan/state (SyncWorkspacePlanFromSubscription only sets Plan::Pro when the
    // subscription is valid()); it also satisfies HostedWorkspaceAccess::allows()
    // via subscription()?->valid(), so the request isn't paused before reaching
    // ChatController.
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_top_up',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Pro->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('top_up_available'))->toBeTrue();
    expect($response->json('top_up_url'))->toBe(url("/app/{$workspace->slug}/billing"));
    expect($response->json('upgrade_available'))->toBeFalse();
});

it('gives free plans the upgrade shape and no top-up', function (): void {
    Feature::define(Billing::class, true);

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['hosted_free_grandfathered_at' => now()])->save();

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Free->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('upgrade_available'))->toBeTrue();
    expect($response->json('top_up_available'))->toBeFalse();
    expect($response->json('top_up_url'))->toBeNull();
});

it('withholds the top-up url when no credit pack has a configured price', function (): void {
    Feature::define(Billing::class, true);
    config()->set('services.stripe.credit_packs', [
        'small' => ['price' => null, 'credits' => 1000],
        'large' => ['price' => null, 'credits' => 5000],
    ]);

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->plan = Plan::Pro;
    $workspace->save();

    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_no_packs',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Pro->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('top_up_available'))->toBeFalse();
    expect($response->json('top_up_url'))->toBeNull();
});

it('names the Free allowance, not the Pro one, when a past-due workspace runs out', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->plan = Plan::Pro;
    $workspace->save();

    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_past_due_exhausted',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Free->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('allowance'))->toBe(Plan::Free->credits())
        ->and($response->json('allowance'))->not->toBe(Plan::Pro->credits())
        ->and($response->json('message'))->toContain((string) Plan::Free->credits());
});
