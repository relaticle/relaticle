<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Agents\CrmAssistant;

mutates(Dashboard::class);

it('shows good morning for a Tokyo user at 6am local time', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-04-19 21:00:00', new DateTimeZone('UTC'))); // 06:00 JST next day

    $user = User::factory()->withPersonalTeam()->create(['timezone' => 'Asia/Tokyo']);
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    Livewire::test(Dashboard::class)->assertSee('Good morning');
});

it('shows good evening for a Los Angeles user at 9pm local time', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-04-20 04:00:00', new DateTimeZone('UTC'))); // 21:00 LA prev day

    $user = User::factory()->withPersonalTeam()->create(['timezone' => 'America/Los_Angeles']);
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    Livewire::test(Dashboard::class)->assertSee('Good evening');
});

it('falls back to app timezone when user has no timezone set', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-04-19 10:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create(['timezone' => null]);
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    Livewire::test(Dashboard::class)->assertSee('Good morning');
});

it('renders the welcome message inline on a fresh workspace', function (): void {
    // Pinned to a morning UTC hour so assertDontSee('Good morning') actually
    // exercises the welcome branch instead of passing by wall-clock luck.
    $this->travelTo(new DateTimeImmutable('2026-08-25 08:00:00', new DateTimeZone('UTC')));

    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create(['name' => 'Dana Reed']);
    $this->actingAs($owner);
    Filament::setTenant($owner->currentTeam);

    Livewire::test(Dashboard::class)
        ->assertSee('Dana')
        ->assertDontSee('Good morning');
});

it('falls back to the time-of-day greeting once the user has replied', function (): void {
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create();
    $this->actingAs($owner);
    Filament::setTenant($owner->currentTeam);

    $conversationId = DB::table('agent_conversations')
        ->where('team_id', $owner->currentTeam->getKey())
        ->value('id');

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $owner->getMorphClass(),
        'participant_id' => (string) $owner->getKey(),
        'agent' => CrmAssistant::class,
        'role' => 'user',
        'content' => 'hello',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(Dashboard::class)->assertSee('Good');
});

it('hides the welcome for a member who does not own the conversation', function (): void {
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;

    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'admin']);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member);
    Filament::setTenant($team);

    Livewire::test(Dashboard::class)->assertSee('Good');
});

it('hides the welcome once the checklist is dismissed', function (): void {
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create();
    $owner->currentTeam->forceFill(['activation_checklist_dismissed_at' => now()])->save();

    $this->actingAs($owner);
    Filament::setTenant($owner->currentTeam);

    Livewire::test(Dashboard::class)->assertSee('Good');
});

it('points the first-run composer at the welcome conversation', function (): void {
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create();
    $this->actingAs($owner);
    Filament::setTenant($owner->currentTeam);

    $conversationId = DB::table('agent_conversations')
        ->where('team_id', $owner->currentTeam->getKey())
        ->value('id');

    // dashboardChatInput's first argument is rendered through @js(), which
    // JSON-encodes the URL and escapes every slash as \/. Comparing against
    // the raw URL would never match the markup, so build the expectation the
    // same way Blade's @js() directive does.
    $welcomeConversationUrl = Js::from(
        ChatConversation::getUrl(['conversationId' => $conversationId])
    )->toHtml();

    Livewire::test(Dashboard::class)
        ->assertSee($welcomeConversationUrl, escape: false);
});
