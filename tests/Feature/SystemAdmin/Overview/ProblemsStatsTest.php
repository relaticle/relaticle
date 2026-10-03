<?php

declare(strict_types=1);

use App\Features\Billing;
use App\Features\EmailIntegration;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\ChatMessageFeedback;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\SystemAdmin\Filament\Resources\ChatMessageFeedbackResource\Pages\ListChatMessageFeedback;
use Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource\Pages\ListConnectedAccounts;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ListUsers;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\ProblemsStats;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(ProblemsStats::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    Feature::define(Billing::class, true);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

function rateMessage(Workspace $workspace, User $user, string $rating, CarbonImmutable $at): ChatMessageFeedback
{
    $conversationId = (string) Str::uuid7();
    $messageId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Rated conversation',
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'agent' => 'test',
        'role' => 'assistant',
        'content' => 'answer',
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    return ChatMessageFeedback::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'message_id' => $messageId,
        'rating' => $rating,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

it('renders on an empty database', function (): void {
    livewire(ProblemsStats::class)
        ->assertOk()
        ->assertSee("What's going wrong?")
        ->assertSee('Trial abuse suspects')
        ->assertSee('Stuck after setup')
        ->assertSee('Left the setup wizard')
        ->assertSee('Thumbs down this week')
        ->assertSee('No owners 3 to 30 days old yet')
        ->assertSee('No signups in the last 30 days')
        ->assertSee('Mailboxes needing attention')
        ->assertSee('No mailboxes connected yet');
});

it('shows the empty states in gray', function (): void {
    $html = livewire(ProblemsStats::class)->html();

    preg_match('/Stuck after setup(.*?)No owners 3 to 30 days old yet/s', $html, $stuck);
    preg_match('/Left the setup wizard(.*?)No signups in the last 30 days/s', $html, $wizard);
    preg_match('/Mailboxes needing attention(.*?)No mailboxes connected yet/s', $html, $mailboxes);

    expect($stuck[1])->not->toContain('fi-color-')
        ->and($wizard[1])->not->toContain('fi-color-')
        ->and($mailboxes[1])->not->toContain('fi-color-');
});

it('counts stuck owners exactly as the stuck list shows them', function (): void {
    $stuck = OverviewData::owner(CarbonImmutable::parse('2026-10-05 10:00:00'));
    $active = OverviewData::owner(CarbonImmutable::parse('2026-10-05 10:00:00'));
    OverviewData::typedMessage(OverviewData::workspaceOf($active), $active, CarbonImmutable::parse('2026-10-06 10:00:00'));
    $tooNew = OverviewData::owner(CarbonImmutable::parse('2026-10-14 10:00:00'));
    $internal = OverviewData::internalOwner();
    OverviewData::workspaceOf($internal)->forceFill(['created_at' => now()->subDays(5)])->save();

    livewire(ProblemsStats::class)->assertSee('50%');

    livewire(ListWorkspaces::class)
        ->filterTable('stuck_after_setup')
        ->assertCanSeeTableRecords([OverviewData::workspaceOf($stuck)])
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($active), OverviewData::workspaceOf($tooNew), OverviewData::workspaceOf($internal)]);
});

it('closes the first three days before a workspace can count as stuck', function (): void {
    $createdAt = CarbonImmutable::parse('2026-10-10 10:00:00');
    $lateStart = OverviewData::owner($createdAt);
    OverviewData::typedMessage(OverviewData::workspaceOf($lateStart), $lateStart, $createdAt->addDays(3));
    $earlyStart = OverviewData::owner($createdAt);
    OverviewData::typedMessage(OverviewData::workspaceOf($earlyStart), $earlyStart, $createdAt->addDays(2)->setTime(23, 0));

    livewire(ProblemsStats::class)->assertSee('50%');

    livewire(ListWorkspaces::class)
        ->filterTable('stuck_after_setup')
        ->assertCanSeeTableRecords([OverviewData::workspaceOf($lateStart)])
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($earlyStart)]);
});

it('counts genuine signups who never made a workspace', function (): void {
    $left = User::factory()->create(['created_at' => now()->subDays(5), 'email_verified_at' => now()->subDays(5)]);
    $stayed = OverviewData::owner(now()->subDays(5));

    livewire(ProblemsStats::class)->assertSee('50%');

    livewire(ListUsers::class)
        ->filterTable('no_workspace')
        ->assertCanSeeTableRecords([$left])
        ->assertCanNotSeeTableRecords([$stayed]);
});

it('counts wizard leavers exactly as the user list opened by the tile shows them', function (): void {
    $left = User::factory()->create(['created_at' => now()->subDays(5), 'email_verified_at' => now()->subDays(5)]);
    $firstDay = User::factory()->create(['created_at' => now()->subDays(29), 'email_verified_at' => now()->subDays(29)]);
    $dayBefore = User::factory()->create(['created_at' => now()->subDays(30), 'email_verified_at' => now()->subDays(30)]);
    $unverified = User::factory()->unverified()->create(['created_at' => now()->subDays(5)]);
    $stayed = OverviewData::owner(now()->subDays(5));

    livewire(ProblemsStats::class)
        ->assertSee('67%')
        ->assertSee('2 of 3 signups in 30 days')
        ->assertSee('filters%5Bsigned_up%5D%5Bfrom%5D='.ViewerTime::today()->subDays(29)->toDateString(), escape: false);

    livewire(ListUsers::class)
        ->filterTable('genuine_signup')
        ->filterTable('no_workspace')
        ->filterTable('signed_up', [
            'from' => ViewerTime::today()->subDays(29)->toDateString(),
            'until' => ViewerTime::today()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$left, $firstDay])
        ->assertCanNotSeeTableRecords([$dayBefore, $unverified, $stayed]);
});

it('keeps each administrator calendar in its own wizard count', function (): void {
    $signedUpAt = CarbonImmutable::parse('2026-09-15 22:00:00');
    User::factory()->create(['created_at' => $signedUpAt, 'email_verified_at' => $signedUpAt]);

    livewire(ProblemsStats::class)->assertSee('No signups in the last 30 days');

    $this->actingAs(SystemAdministrator::factory()->create(['timezone' => 'Asia/Yerevan']), 'sysadmin');

    livewire(ProblemsStats::class)->assertSee('1 of 1 signups in 30 days');
});

it('splits wizard leavers by signup method the way the signup method column reads it', function (): void {
    $viaGoogle = User::factory()->create(['created_at' => now()->subDays(5), 'email_verified_at' => now()->subDays(5)]);
    UserSocialAccount::factory()->create(['user_id' => $viaGoogle->getKey(), 'provider_name' => 'google', 'created_at' => $viaGoogle->created_at]);
    $viaMicrosoft = User::factory()->create(['created_at' => now()->subDays(7), 'email_verified_at' => now()->subDays(7)]);
    UserSocialAccount::factory()->create(['user_id' => $viaMicrosoft->getKey(), 'provider_name' => 'microsoft', 'created_at' => $viaMicrosoft->created_at]);
    $linkedLater = User::factory()->create(['created_at' => now()->subDays(5), 'email_verified_at' => now()->subDays(5)]);
    UserSocialAccount::factory()->create(['user_id' => $linkedLater->getKey(), 'provider_name' => 'google', 'created_at' => now()->subDay()]);
    $password = User::factory()->create(['created_at' => now()->subDays(6), 'email_verified_at' => now()->subDays(6)]);
    OverviewData::owner(now()->subDays(5));

    livewire(ProblemsStats::class)
        ->assertSee('80%')
        ->assertSee('4 of 5 signups in 30 days: 2 Password, 1 Google, 1 Microsoft');

    livewire(ListUsers::class)
        ->assertTableColumnStateSet('signup_method', 'Google', $viaGoogle)
        ->assertTableColumnStateSet('signup_method', 'Microsoft', $viaMicrosoft)
        ->assertTableColumnStateSet('signup_method', 'Password', $linkedLater)
        ->assertTableColumnStateSet('signup_method', 'Password', $password);
});

it('counts trial abuse suspects as the workspace list shows them and notes who is still spending', function (): void {
    config()->set('system-admin.abuse_timezones', ['Asia/Tehran']);

    $suspect = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner(attributes: ['timezone' => 'Asia/Tehran'])));
    $genuineOwner = OverviewData::owner(attributes: ['timezone' => 'Asia/Tehran']);
    $genuine = OverviewData::trial(OverviewData::workspaceOf($genuineOwner));
    OverviewData::ownRecord($genuine, $genuineOwner, now());

    AiCreditTransaction::query()->create([
        'workspace_id' => $suspect->getKey(), 'user_id' => $suspect->user_id, 'idempotency_key' => 'p-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'metadata' => [], 'created_at' => now()->subDay(),
    ]);

    livewire(ProblemsStats::class)
        ->assertSeeInOrder(['Trial abuse suspects', '1', 'Some are still spending credits'])
        ->assertSee('filters%5Babuse_suspect%5D%5BisActive%5D=1', escape: false);

    livewire(ListWorkspaces::class)
        ->filterTable('abuse_suspect')
        ->assertCanSeeTableRecords([$suspect])
        ->assertCanNotSeeTableRecords([$genuine]);
});

it('says no suspect spent credits this week when their spending is older', function (): void {
    config()->set('system-admin.abuse_timezones', ['Asia/Tehran']);

    $suspect = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner(attributes: ['timezone' => 'Asia/Tehran'])));
    AiCreditTransaction::query()->create([
        'workspace_id' => $suspect->getKey(), 'user_id' => $suspect->user_id, 'idempotency_key' => 'p-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'metadata' => [], 'created_at' => now()->subDays(8),
    ]);

    livewire(ProblemsStats::class)->assertSee('None spent credits this week');
});

it('counts thumbs down since Monday exactly as the feedback list the tile opens shows them', function (): void {
    $user = OverviewData::owner();
    $workspace = OverviewData::workspaceOf($user);

    $today = rateMessage($workspace, $user, ChatMessageFeedback::RATING_DOWN, now());
    $monday = rateMessage($workspace, $user, ChatMessageFeedback::RATING_DOWN, CarbonImmutable::parse('2026-10-12 00:00:00'));
    $sunday = rateMessage($workspace, $user, ChatMessageFeedback::RATING_DOWN, CarbonImmutable::parse('2026-10-11 23:59:59'));
    $old = rateMessage($workspace, $user, ChatMessageFeedback::RATING_DOWN, now()->subWeeks(2));
    $up = rateMessage($workspace, $user, ChatMessageFeedback::RATING_UP, now());

    livewire(ProblemsStats::class)
        ->assertSeeInOrder(['Thumbs down this week', '2', 'Answers people rated down since Monday'])
        ->assertSee('filters%5Brating%5D%5Bvalue%5D=down', escape: false)
        ->assertSee('filters%5Bthis_week%5D%5BisActive%5D=1', escape: false);

    livewire(ListChatMessageFeedback::class)
        ->filterTable('rating', ChatMessageFeedback::RATING_DOWN)
        ->filterTable('this_week')
        ->assertCanSeeTableRecords([$today, $monday])
        ->assertCanNotSeeTableRecords([$sunday, $old, $up]);

    expect(ProblemsStats::thumbsDownThisWeek())->toBe(2);
});

it('hides the abuse tile when billing is off', function (): void {
    Feature::define(Billing::class, false);

    livewire(ProblemsStats::class)->assertDontSee('Trial abuse suspects');
});

it('starts the thumbs down count again when a new week begins', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 23:55:00'));
    $user = OverviewData::owner();
    $workspace = OverviewData::workspaceOf($user);
    rateMessage($workspace, $user, ChatMessageFeedback::RATING_DOWN, now());

    preg_match('/Thumbs down this week.*?fi-wi-stats-overview-stat-value">\s*(\d+)\s*</s', livewire(ProblemsStats::class)->html(), $sunday);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 00:02:00'));

    preg_match('/Thumbs down this week.*?fi-wi-stats-overview-stat-value">\s*(\d+)\s*</s', livewire(ProblemsStats::class)->html(), $monday);

    expect($sunday[1])->toBe('1')
        ->and($monday[1])->toBe('0');
});

it('explains every number in a tooltip', function (): void {
    livewire(ProblemsStats::class)
        ->assertSee('spent at least half their chat credits on premium models')
        ->assertSee('typed no chat message in their first 3 days')
        ->assertSee('never created or joined a workspace')
        ->assertSee('rated thumbs down since Monday');
});

it('counts mailboxes needing attention exactly as the mailbox list shows them', function (): void {
    $healthy = ConnectedAccount::factory()->create(['sync_cursor' => 'cursor', 'last_synced_at' => now()->subMinutes(5)]);
    $failed = ConnectedAccount::factory()->error()->create();
    $needsSignIn = ConnectedAccount::factory()->create(['status' => EmailAccountStatus::REAUTH_REQUIRED]);
    $late = ConnectedAccount::factory()->create(['sync_cursor' => 'cursor', 'last_synced_at' => now()->subHours(2)]);

    livewire(ProblemsStats::class)
        ->assertSee('Mailboxes needing attention')
        ->assertSee('2 failing, 1 late to sync')
        ->assertSee('filters%5Bneeds_attention%5D%5BisActive%5D=1', escape: false);

    livewire(ListConnectedAccounts::class)
        ->filterTable('needs_attention')
        ->assertCanSeeTableRecords([$failed, $needsSignIn, $late])
        ->assertCanNotSeeTableRecords([$healthy]);
});

it('reports healthy mailboxes in green and a late sync alone in amber', function (): void {
    ConnectedAccount::factory()->create(['sync_cursor' => 'cursor', 'last_synced_at' => now()->subMinutes(5)]);

    $html = livewire(ProblemsStats::class)->assertSee('Every connected mailbox is syncing')->html();
    preg_match('/Mailboxes needing attention(.*?)Every connected mailbox is syncing/s', $html, $healthy);

    ConnectedAccount::factory()->create(['sync_cursor' => 'cursor', 'last_synced_at' => now()->subHours(2)]);
    Cache::flush();

    $html = livewire(ProblemsStats::class)->assertSee('0 failing, 1 late to sync')->html();
    preg_match('/Mailboxes needing attention(.*?)0 failing, 1 late to sync/s', $html, $late);

    expect($healthy[1])->toContain('fi-color-success')
        ->and($late[1])->toContain('fi-color-warning');
});

it('leaves the mailbox tile out while the email integration is off', function (): void {
    config()->set('relaticle.features.email_integration', false);
    Feature::flushCache();
    Feature::for(null)->deactivate(EmailIntegration::class);

    livewire(ProblemsStats::class)->assertDontSee('Mailboxes needing attention');
});
