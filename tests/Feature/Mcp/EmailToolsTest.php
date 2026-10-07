<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Enums\WorkspaceRole;
use App\Features\EmailIntegration;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Email\CreateEmailDraftTool;
use App\Mcp\Tools\Email\GetEmailTool;
use App\Mcp\Tools\Email\ListEmailAccountsTool;
use App\Mcp\Tools\Email\ListEmailsTool;
use App\Mcp\Tools\Email\SendEmailTool;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Actions\PrepareAgentEmail;
use Relaticle\EmailIntegration\Actions\PrepareAgentEmailDraft;
use Relaticle\EmailIntegration\Actions\QueueAgentEmailAction;
use Relaticle\EmailIntegration\Actions\SaveAgentEmailDraft;
use Relaticle\EmailIntegration\Actions\SaveMailboxSharingTierAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Jobs\SendEmailJob;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Livewire\OutboxTable;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;
use Relaticle\EmailIntegration\Policies\EmailPolicy;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;
use Relaticle\EmailIntegration\Support\AgentEmailBody;
use Relaticle\EmailIntegration\Support\EmailForAgent;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(ListEmailsTool::class, GetEmailTool::class, ListEmailAccountsTool::class, CreateEmailDraftTool::class, SendEmailTool::class, QueueAgentEmailAction::class, PrepareAgentEmail::class, PrepareAgentEmailDraft::class, SaveAgentEmailDraft::class, QueuedSendNotifier::class, AgentEmailBody::class, SignatureBlock::class, VisibleEmailsQuery::class, EmailForAgent::class, EmailPolicy::class);

beforeEach(function (): void {
    $this->viewer = User::factory()->withWorkspace()->create();
    $this->workspace = $this->viewer->currentWorkspace;

    $this->coworker = User::factory()->create();
    $this->coworker->workspaces()->attach($this->workspace);
    $this->coworker->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $this->viewerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]));

    $this->coworkerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->coworker->id,
    ]));

    $this->emailFrom = function (User $owner, array $attributes = [], string $sender = 'client@acme.test'): Email {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $owner->id,
            'connected_account_id' => $owner->is($this->viewer) ? $this->viewerAccount->getKey() : $this->coworkerAccount->getKey(),
            'is_internal' => false,
            ...$attributes,
        ]);

        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => $sender,
            'name' => 'Acme Client',
            'role' => EmailParticipantRole::FROM,
        ]);

        return $email;
    };
});

function listedEmails(User $user, array $arguments = []): array
{
    $items = [];

    RelaticleServer::actingAs($user)
        ->tool(ListEmailsTool::class, $arguments)
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$items): AssertableJson {
            $items = $json->toArray()['items'];

            return $json->etc();
        });

    return $items;
}

function listedToolNames(User $user, array $abilities): array
{
    auth()->forgetGuards();

    return test()
        ->withToken($user->createToken('test-'.Str::random(6), $abilities)->plainTextToken)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->json('result.tools.*.name');
}

function listedToolNamesIn(User $user, array $abilities, Workspace $workspace): array
{
    auth()->forgetGuards();

    return test()
        ->withToken($user->createToken('test-'.Str::random(6), $abilities)->plainTextToken)
        ->withHeader('X-Workspace-Id', $workspace->id)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->json('result.tools.*.name');
}

it('shows the caller their own email in full', function (): void {
    $email = ($this->emailFrom)($this->viewer, ['subject' => 'Renewal terms', 'snippet' => 'Here is the draft contract']);

    $items = listedEmails($this->viewer);

    expect($items)->toHaveCount(1)
        ->and($items[0]['id'])->toBe($email->id)
        ->and($items[0]['access'])->toBe('full')
        ->and($items[0]['subject'])->toBe('Renewal terms')
        ->and($items[0]['snippet'])->toBe('Here is the draft contract')
        ->and($items[0]['participants'][0])->toBe(['role' => 'from', 'name' => 'Acme Client', 'email' => 'client@acme.test']);
});

it('hides subject and snippet of a teammate email shared as metadata only', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    $items = listedEmails($this->viewer);

    expect($items)->toHaveCount(1)
        ->and($items[0]['access'])->toBe('metadata_only')
        ->and($items[0]['subject'])->toBeNull()
        ->and($items[0]['snippet'])->toBeNull()
        ->and($items[0]['participants'])->not->toBeEmpty();
});

it('shows the subject but not the snippet of a teammate email shared at subject level', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::SUBJECT, 'subject' => 'Pricing call']);

    $items = listedEmails($this->viewer);

    expect($items[0]['access'])->toBe('subject')
        ->and($items[0]['subject'])->toBe('Pricing call')
        ->and($items[0]['snippet'])->toBeNull();
});

it('shows subject and snippet of a teammate email shared in full', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL, 'subject' => 'Kickoff', 'snippet' => 'See you Monday']);

    $items = listedEmails($this->viewer);

    expect($items[0]['access'])->toBe('full')
        ->and($items[0]['subject'])->toBe('Kickoff')
        ->and($items[0]['snippet'])->toBe('See you Monday');
});

it('leaves a private teammate email out of the list', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    expect(listedEmails($this->viewer))->toBe([]);
});

it('lets a per-viewer share lower access below the email default', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL, 'subject' => 'Board notes', 'snippet' => 'Confidential']);

    EmailShare::factory()->tier(EmailPrivacyTier::METADATA_ONLY)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $email->id,
        'shared_with' => $this->viewer->id,
        'shared_by' => $this->coworker->id,
    ]);

    $items = listedEmails($this->viewer);

    expect($items[0]['access'])->toBe('metadata_only')
        ->and($items[0]['subject'])->toBeNull()
        ->and($items[0]['snippet'])->toBeNull()
        ->and(listedEmails($this->viewer, ['search' => 'Board notes']))->toBe([]);
});

it('leaves another workspace email out of the list', function (): void {
    $otherWorkspace = Workspace::factory()->create();

    Email::factory()->full()->create(['workspace_id' => $otherWorkspace->id]);

    expect(listedEmails($this->viewer))->toBe([]);
});

it('leaves unsent mail out of the list', function (EmailStatus $status): void {
    ($this->emailFrom)($this->viewer, ['status' => $status]);
    ($this->emailFrom)($this->coworker, ['status' => $status, 'privacy_tier' => EmailPrivacyTier::FULL]);

    expect(listedEmails($this->viewer))->toBe([]);
})->with([
    'draft' => EmailStatus::DRAFT,
    'queued' => EmailStatus::QUEUED,
    'sending' => EmailStatus::SENDING,
    'failed' => EmailStatus::FAILED,
    'cancelled' => EmailStatus::CANCELLED,
]);

it('shows bcc recipients to the mailbox owner only', function (): void {
    $own = ($this->emailFrom)($this->viewer);
    $teammates = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL]);

    foreach ([$own, $teammates] as $email) {
        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => 'hidden@acme.test',
            'name' => null,
            'role' => EmailParticipantRole::BCC,
        ]);
    }

    $items = collect(listedEmails($this->viewer))->keyBy('id');

    expect(collect($items[$own->id]['participants'])->pluck('role'))->toContain('bcc')
        ->and(collect($items[$teammates->id]['participants'])->pluck('role'))->not->toContain('bcc');
});

it('lists one row for a message held in two mailboxes', function (): void {
    $own = ($this->emailFrom)($this->viewer, ['rfc_message_id' => '<same@acme.test>']);
    ($this->emailFrom)($this->coworker, ['rfc_message_id' => '<same@acme.test>', 'privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    $items = listedEmails($this->viewer);

    expect($items)->toHaveCount(1)
        ->and($items[0]['id'])->toBe($own->id)
        ->and($items[0]['access'])->toBe('full');
});

it('filters by the linked record', function (): void {
    $person = People::factory()->recycle([$this->viewer, $this->workspace])->create();
    $linked = ($this->emailFrom)($this->viewer);
    ($this->emailFrom)($this->viewer);

    $person->emails()->attach($linked->id, ['link_source' => 'manual']);

    $items = listedEmails($this->viewer, ['record_type' => 'people', 'record_id' => $person->id]);

    expect(array_column($items, 'id'))->toBe([$linked->id]);
});

it('lists nothing for a record whose mailbox the workspace protects', function (): void {
    $person = People::factory()->recycle([$this->viewer, $this->workspace])->create();

    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $person->saveCustomFieldValue($emailsField, ['vip@acme.test'], $this->workspace);

    $email = ($this->emailFrom)($this->viewer, [], 'vip@acme.test');
    $person->emails()->attach($email->id, ['link_source' => 'manual']);

    $arguments = ['record_type' => 'people', 'record_id' => $person->id];

    expect(array_column(listedEmails($this->viewer, $arguments), 'id'))->toBe([$email->id]);

    WorkspaceEmailBlocklist::factory()->protected()->email('vip@acme.test')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    app()->forgetScopedInstances();

    expect(listedEmails($this->viewer, $arguments))->toBe([]);
});

it('lists nothing for a record in another workspace', function (): void {
    $foreign = People::factory()->create();
    $local = People::factory()->recycle([$this->viewer, $this->workspace])->create();
    $linked = ($this->emailFrom)($this->viewer);
    ($this->emailFrom)($this->viewer);

    $local->emails()->attach($linked->id, ['link_source' => 'manual']);
    $foreign->emails()->attach($linked->id, ['link_source' => 'manual']);

    expect(listedEmails($this->viewer, ['record_type' => 'people', 'record_id' => $foreign->id]))->toBe([])
        ->and(array_column(listedEmails($this->viewer, ['record_type' => 'people', 'record_id' => $local->id]), 'id'))->toBe([$linked->id]);
});

it('filters by search, direction and sent date', function (): void {
    $old = ($this->emailFrom)($this->viewer, ['subject' => 'Invoice 14', 'sent_at' => now()->subDays(10)]);
    $recent = ($this->emailFrom)($this->viewer, ['subject' => 'Invoice 15', 'sent_at' => now()->subDay()]);
    $sent = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'connected_account_id' => $this->viewerAccount->getKey(),
        'subject' => 'Follow up',
    ]);

    expect(array_column(listedEmails($this->viewer, ['search' => 'Invoice']), 'id'))->toEqualCanonicalizing([$old->id, $recent->id])
        ->and(array_column(listedEmails($this->viewer, ['direction' => 'outbound']), 'id'))->toBe([$sent->id])
        ->and(array_column(listedEmails($this->viewer, ['search' => 'Invoice', 'sent_after' => now()->subDays(3)->toIso8601String()]), 'id'))->toBe([$recent->id])
        ->and(array_column(listedEmails($this->viewer, ['search' => 'Invoice', 'sent_before' => now()->subDays(3)->toIso8601String()]), 'id'))->toBe([$old->id])
        ->and(array_column(listedEmails($this->viewer, ['search' => 'acme.test']), 'id'))->toEqualCanonicalizing([$old->id, $recent->id]);
});

it('reads a sent date with an offset at the instant it names', function (): void {
    $afterMidnight = ($this->emailFrom)($this->viewer, ['sent_at' => '2026-10-07 00:30:00']);
    $beforeMidnight = ($this->emailFrom)($this->viewer, ['sent_at' => '2026-10-06 23:30:00']);

    $instant = '2026-10-07T09:00:00+09:00';

    expect(array_column(listedEmails($this->viewer, ['sent_after' => $instant]), 'id'))->toBe([$afterMidnight->id])
        ->and(array_column(listedEmails($this->viewer, ['sent_before' => $instant]), 'id'))->toBe([$beforeMidnight->id]);
});

it('lists newest first and pages', function (): void {
    $older = ($this->emailFrom)($this->viewer, ['sent_at' => now()->subHours(2)]);
    $newer = ($this->emailFrom)($this->viewer, ['sent_at' => now()->subHour()]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class, ['per_page' => 1])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items.0.id', $newer->id)
            ->where('has_more', true)
            ->where('next_page', 2)
            ->missing('total')
            ->etc());

    expect(array_column(listedEmails($this->viewer, ['per_page' => 1, 'page' => 2]), 'id'))->toBe([$older->id]);
});

it('rejects a record id without its type', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class, ['record_id' => 'abc'])
        ->assertHasErrors();
});

it('keeps a full page of teammate email under a fixed query budget', function (): void {
    foreach (range(1, 25) as $i) {
        ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::SUBJECT]);
    }

    DB::enableQueryLog();
    $items = listedEmails($this->viewer, ['per_page' => 25]);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($items)->toHaveCount(25)
        ->and($queries)->toBeLessThan(76);
});

it('lists the email tool only for a token that holds the grant', function (): void {
    expect(listedToolNames($this->viewer, ['read']))->not->toContain('list-emails-tool')
        ->and(listedToolNames($this->viewer, ['read', 'email:read']))->toContain('list-emails-tool');
});

it('does not list the email tool while the email feature is off', function (): void {
    Feature::define(EmailIntegration::class, false);

    expect(listedToolNames($this->viewer, ['read', 'email:read']))->not->toContain('list-emails-tool')
        ->not->toContain('get-email-tool');
});

function fetchedEmail(User $user, string $id): array
{
    $data = [];

    RelaticleServer::actingAs($user)
        ->tool(GetEmailTool::class, ['id' => $id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$data): AssertableJson {
            $data = $json->toArray()['data'];

            return $json->etc();
        });

    return $data;
}

it('returns the body of an email the caller may read in full', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL, 'subject' => 'Kickoff']);

    EmailBody::query()->create(['email_id' => $email->id, 'body_html' => '<p>See you Monday</p>', 'body_text' => 'See you Monday']);
    EmailAttachment::factory()->create(['email_id' => $email->id, 'filename' => 'agenda.pdf', 'mime_type' => 'application/pdf', 'size' => 2048]);

    $data = fetchedEmail($this->viewer, $email->id);

    expect($data['access'])->toBe('full')
        ->and($data['subject'])->toBe('Kickoff')
        ->and($data['body_text'])->toBe('See you Monday')
        ->and($data['body_truncated'])->toBeFalse()
        ->and($data['attachments'])->toBe([['filename' => 'agenda.pdf', 'mime_type' => 'application/pdf', 'size' => 2048]]);
});

it('withholds the body and attachment names below full access', function (EmailPrivacyTier $tier): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => $tier]);

    EmailBody::query()->create(['email_id' => $email->id, 'body_html' => '<p>Secret</p>', 'body_text' => 'Secret']);
    EmailAttachment::factory()->create(['email_id' => $email->id, 'filename' => 'secret.pdf']);

    $data = fetchedEmail($this->viewer, $email->id);

    expect($data['access'])->toBe($tier->value)
        ->and($data['body_text'])->toBeNull()
        ->and($data['attachments'])->toBe([]);
})->with([
    'metadata only' => EmailPrivacyTier::METADATA_ONLY,
    'subject' => EmailPrivacyTier::SUBJECT,
]);

it('cuts a very large body and says so', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    EmailBody::query()->create(['email_id' => $email->id, 'body_html' => '', 'body_text' => str_repeat('a', 50_000)]);

    $data = fetchedEmail($this->viewer, $email->id);

    expect(mb_strlen($data['body_text']))->toBe(20_000)
        ->and($data['body_truncated'])->toBeTrue();
});

it('answers not found for an email the caller may not see', function (): void {
    $private = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::PRIVATE]);
    $foreign = Email::factory()->full()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $draft = ($this->emailFrom)($this->viewer, ['status' => EmailStatus::DRAFT]);

    foreach ([$private->id, $foreign->id, $draft->id, 'does-not-exist'] as $id) {
        RelaticleServer::actingAs($this->viewer)
            ->tool(GetEmailTool::class, ['id' => $id])
            ->assertHasErrors(["Email with ID [{$id}] not found."]);
    }
});

it('lists the get email tool only for a token that holds the grant', function (): void {
    expect(listedToolNames($this->viewer, ['read']))->not->toContain('get-email-tool')
        ->and(listedToolNames($this->viewer, ['read', 'email:read']))->toContain('get-email-tool');
});

it('keeps a teammate internal email out of the list even when it is shared in full', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['is_internal' => true, 'privacy_tier' => EmailPrivacyTier::FULL]);

    EmailShare::factory()->tier(EmailPrivacyTier::FULL)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $email->id,
        'shared_with' => $this->viewer->id,
        'shared_by' => $this->coworker->id,
    ]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items', [])
            ->where('has_more', false)
            ->etc());

    RelaticleServer::actingAs($this->viewer)
        ->tool(GetEmailTool::class, ['id' => $email->id])
        ->assertHasErrors(["Email with ID [{$email->id}] not found."]);
});

it('does not let a hidden internal email take a place on the page', function (): void {
    $hidden = ($this->emailFrom)($this->coworker, ['is_internal' => true, 'privacy_tier' => EmailPrivacyTier::FULL, 'sent_at' => now()->subHour()]);
    $own = ($this->emailFrom)($this->viewer, ['sent_at' => now()->subHours(2)]);

    EmailShare::factory()->tier(EmailPrivacyTier::FULL)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $hidden->id,
        'shared_with' => $this->viewer->id,
        'shared_by' => $this->coworker->id,
    ]);

    expect(array_column(listedEmails($this->viewer, ['per_page' => 1]), 'id'))->toBe([$own->id]);
});

it('still lists the caller own internal email', function (): void {
    $email = ($this->emailFrom)($this->viewer, ['is_internal' => true]);

    expect(array_column(listedEmails($this->viewer), 'id'))->toBe([$email->id]);
});

it('returns text for an html-only body', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    EmailBody::query()->create([
        'email_id' => $email->id,
        'body_text' => null,
        'body_html' => '<style>p{color:red}</style><p>Hello <b>Dana</b>,</p><p>See you &amp; the team<br>Monday</p><script>x()</script>',
    ]);

    $text = fetchedEmail($this->viewer, $email->id)['body_text'];

    expect($text)->toContain('Hello Dana,')
        ->toContain("See you & the team\nMonday")
        ->not->toContain('color:red')
        ->not->toContain('x()');
});

it('returns a null body for an email with no body row', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    expect(fetchedEmail($this->viewer, $email->id)['body_text'])->toBeNull();
});

it('cuts a very large html-only body and says so', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    EmailBody::query()->create(['email_id' => $email->id, 'body_text' => null, 'body_html' => '<p>'.str_repeat('a', 50_000).'</p>']);

    $data = fetchedEmail($this->viewer, $email->id);

    expect(mb_strlen($data['body_text']))->toBe(20_000)
        ->and($data['body_truncated'])->toBeTrue();
});

it('accepts numeric strings for the page arguments', function (): void {
    ($this->emailFrom)($this->viewer);

    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class, ['per_page' => '10', 'page' => '1'])
        ->assertOk();
});

it('lists cc recipients only where the body is shared', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    foreach ([EmailParticipantRole::TO, EmailParticipantRole::CC] as $role) {
        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => "{$role->value}@acme.test",
            'name' => null,
            'role' => $role,
        ]);
    }

    $roles = fn (): array => collect(listedEmails($this->viewer)[0]['participants'])->pluck('role')->all();

    expect($roles())->toEqualCanonicalizing(['from', 'to']);

    $email->forceFill(['privacy_tier' => EmailPrivacyTier::FULL])->save();

    expect($roles())->toEqualCanonicalizing(['from', 'to', 'cc']);
});

it('treats an empty string argument as not given', function (): void {
    ($this->emailFrom)($this->viewer, ['direction' => 'inbound']);
    ($this->emailFrom)($this->viewer, ['direction' => 'inbound']);
    ($this->emailFrom)($this->viewer, ['direction' => 'outbound']);

    $expected = array_column(listedEmails($this->viewer, ['direction' => 'inbound']), 'id');

    expect($expected)->toHaveCount(2)
        ->and(array_column(listedEmails($this->viewer, ['search' => '', 'sent_after' => '', 'thread_id' => '', 'direction' => 'inbound']), 'id'))->toEqualCanonicalizing($expected);
});

it('lists a private teammate email the viewer was shared in full', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    EmailShare::factory()->tier(EmailPrivacyTier::FULL)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $email->id,
        'shared_with' => $this->viewer->id,
        'shared_by' => $this->coworker->id,
    ]);

    $items = listedEmails($this->viewer);

    expect(array_column($items, 'id'))->toBe([$email->id])
        ->and($items[0]['access'])->toBe('full');
});

it('reads a teammate copy in full when the caller holds a synced copy of the message', function (): void {
    ($this->emailFrom)($this->viewer, ['rfc_message_id' => '<held@acme.test>']);
    $teammateCopy = ($this->emailFrom)($this->coworker, ['rfc_message_id' => '<held@acme.test>', 'privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    expect(fetchedEmail($this->viewer, $teammateCopy->id)['access'])->toBe('full');
});

it('hides mail from a blocked address, even from the mailbox owner', function (): void {
    WorkspaceEmailBlocklist::factory()->blocked()->email('blocked@acme.test')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $email = ($this->emailFrom)($this->viewer, [], 'blocked@acme.test');

    expect(listedEmails($this->viewer))->toBe([]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(GetEmailTool::class, ['id' => $email->id])
        ->assertHasErrors(["Email with ID [{$email->id}] not found."]);
});

it('hides a teammate email whose only participant is protected', function (): void {
    WorkspaceEmailBlocklist::factory()->protected()->email('vip@acme.test')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL], 'vip@acme.test');

    expect(listedEmails($this->viewer))->toBe([]);
});

it('hides a teammate email that matches the mailbox blocklist', function (): void {
    EmailBlocklist::factory()->email('spam@acme.test')->create([
        'user_id' => $this->coworker->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->coworkerAccount->getKey(),
    ]);

    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL], 'spam@acme.test');

    expect(listedEmails($this->viewer))->toBe([]);
});

it('hides a teammate email from a disconnected mailbox', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL]);

    $this->coworkerAccount->delete();

    expect(listedEmails($this->viewer))->toBe([]);
});

it('does not let search guess a subject or snippet the viewer cannot see', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY, 'subject' => 'Acquisition plan', 'snippet' => 'Budget']);
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::SUBJECT, 'subject' => 'Weekly sync', 'snippet' => 'Layoffs coming']);

    expect(listedEmails($this->viewer, ['search' => 'Acquisition plan']))->toBe([])
        ->and(listedEmails($this->viewer, ['search' => 'Layoffs']))->toBe([]);
});

it('filters by thread', function (): void {
    $inThread = ($this->emailFrom)($this->viewer, ['thread_id' => 'thread-one']);
    ($this->emailFrom)($this->viewer, ['thread_id' => 'thread-two']);

    expect(array_column(listedEmails($this->viewer, ['thread_id' => 'thread-one']), 'id'))->toBe([$inThread->id]);
});

it('rejects a record type without its id', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class, ['record_type' => 'people'])
        ->assertHasErrors();
});

it('shows bcc recipients of one email to the mailbox owner only', function (): void {
    $own = ($this->emailFrom)($this->viewer);
    $teammates = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL]);

    foreach ([$own, $teammates] as $email) {
        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => 'hidden@acme.test',
            'name' => null,
            'role' => EmailParticipantRole::BCC,
        ]);
    }

    expect(collect(fetchedEmail($this->viewer, $own->id)['participants'])->pluck('role'))->toContain('bcc')
        ->and(collect(fetchedEmail($this->viewer, $teammates->id)['participants'])->pluck('role'))->not->toContain('bcc');
});

function emailToolData(User $user, string $tool, array $arguments = []): array
{
    $data = [];

    RelaticleServer::actingAs($user)
        ->tool($tool, $arguments)
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$data): AssertableJson {
            $data = $json->toArray();

            return $json->etc();
        });

    return $data;
}

function draftArguments(ConnectedAccount $account, array $overrides = []): array
{
    return [
        'connected_account_id' => $account->getKey(),
        'to' => ['client@acme.test'],
        'subject' => 'Next steps',
        'body' => "Hi Dana,\n\nHere is the **plan**.",
        ...$overrides,
    ];
}

it('lists only the caller own mailboxes, with whether each can send', function (): void {
    $receiveOnly = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->withoutSend()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]));

    $items = collect(emailToolData($this->viewer, ListEmailAccountsTool::class)['items'])->keyBy('id');

    expect($items->keys()->all())->toEqualCanonicalizing([$this->viewerAccount->id, $receiveOnly->id])
        ->and($items[$this->viewerAccount->id]['email'])->toBe($this->viewerAccount->email_address)
        ->and($items[$this->viewerAccount->id]['can_send'])->toBeTrue()
        ->and($items[$receiveOnly->id]['can_send'])->toBeFalse();
});

it('saves a private draft and sends nothing', function (): void {
    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['cc' => ['boss@acme.test']]));

    $draft = Email::query()->with(['body', 'participants'])->findOrFail($data['id']);

    expect($data['status'])->toBe('draft')
        ->and($draft->status)->toBe(EmailStatus::DRAFT)
        ->and($draft->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE)
        ->and($draft->creation_source)->toBe(EmailCreationSource::MCP)
        ->and($draft->user_id)->toBe($this->viewer->id)
        ->and($draft->subject)->toBe('Next steps')
        ->and($draft->body->body_html)->toContain('<strong>plan</strong>')
        ->and($draft->participants->pluck('email_address', 'role.value')->sortKeys()->all())->toBe(['cc' => 'boss@acme.test', 'to' => 'client@acme.test'])
        ->and(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('escapes raw html in a draft body', function (): void {
    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, [
        'body' => 'Hi <script>alert(1)</script> <img src=x onerror=alert(1)> **safe**',
    ]));

    $html = Email::query()->with('body')->findOrFail($data['id'])->body->body_html;

    expect($html)->not->toContain('<script')
        ->not->toContain('<img')
        ->toContain('<strong>safe</strong>');
});

it('keeps merge tags literal in a draft body', function (): void {
    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, [
        'body' => 'Dated {today} and {{ today }} and {first_name}',
    ]));

    $html = Email::query()->with('body')->findOrFail($data['id'])->body->body_html;

    expect(html_entity_decode(strip_tags($html)))->toBe('Dated {today} and {{ today }} and {first_name}')
        ->and($html)->not->toContain(now()->toFormattedDateString());
});

it('drops an unsafe link and keeps a single line break in a draft body', function (): void {
    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, [
        'body' => "Thanks,\nDana [click](javascript:alert(1)) [site](https://acme.test)",
    ]));

    $html = Email::query()->with('body')->findOrFail($data['id'])->body->body_html;

    expect($html)->not->toContain('javascript:')
        ->toContain('href="https://acme.test"')
        ->toContain("Thanks,<br />\nDana");
});

it('adds the mailbox default signature to a draft as a signature block', function (): void {
    $signature = EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->viewerAccount->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'content_html' => '<p>Dana, Acme</p>',
    ]);

    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount));

    $html = Email::query()->with('body')->findOrFail($data['id'])->body->body_html;

    expect($html)->toContain('data-id="'.SignatureBlock::ID.'"')
        ->toContain((string) $signature->getKey())
        ->toContain(base64_encode('<p>Dana, Acme</p>'));
});

it('leaves the signature out when asked to, or when the mailbox has no default', function (): void {
    $without = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount));

    EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->viewerAccount->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]);

    $optedOut = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['include_signature' => false]));

    foreach ([$without['id'], $optedOut['id']] as $id) {
        expect(Email::query()->with('body')->findOrFail($id)->body->body_html)
            ->not->toContain('data-id="'.SignatureBlock::ID.'"');
    }
});

it('refuses a draft from a mailbox the caller does not own', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($this->coworkerAccount))
        ->assertHasErrors(["Mailbox with ID [{$this->coworkerAccount->id}] not found."]);

    expect(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(0);
});

it('refuses a draft in a mailbox that is disconnected', function (): void {
    $disconnected = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->disconnected()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]));

    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($disconnected))
        ->assertHasErrors(["Mailbox with ID [{$disconnected->id}] not found."]);

    expect(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(0);
});

it('accepts a draft in a mailbox in the error state and lists it as unable to send', function (): void {
    $errored = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->error()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]));

    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($errored));
    $items = collect(emailToolData($this->viewer, ListEmailAccountsTool::class)['items'])->keyBy('id');

    expect(Email::query()->findOrFail($data['id'])->connected_account_id)->toBe($errored->id)
        ->and($items[$errored->id]['can_send'])->toBeFalse();
});

it('refuses a draft in the caller own mailbox of another workspace and does not list it', function (): void {
    $elsewhere = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'user_id' => $this->viewer->id,
    ]));

    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($elsewhere))
        ->assertHasErrors(["Mailbox with ID [{$elsewhere->id}] not found."]);

    expect(array_column(emailToolData($this->viewer, ListEmailAccountsTool::class)['items'], 'id'))->toBe([$this->viewerAccount->id]);
});

it('threads a reply draft onto an email the caller may view', function (): void {
    $original = ($this->emailFrom)($this->viewer, ['rfc_message_id' => '<orig@acme.test>']);

    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['in_reply_to_email_id' => $original->id]));

    $draft = Email::query()->findOrFail($data['id']);

    expect($draft->in_reply_to)->toBe('<orig@acme.test>')
        ->and($draft->creation_source)->toBe(EmailCreationSource::REPLY);
});

it('stamps a reply draft as a plain assistant draft when the original has no message id to thread on', function (): void {
    $original = ($this->emailFrom)($this->viewer, ['rfc_message_id' => null, 'thread_id' => 'thread-1']);

    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['in_reply_to_email_id' => $original->id]));

    $draft = Email::query()->findOrFail($data['id']);

    expect($draft->in_reply_to)->toBeNull()
        ->and($draft->thread_id)->toBeNull()
        ->and($draft->creation_source)->toBe(EmailCreationSource::MCP);
});

it('refuses a reply draft aimed at an email the caller may not view', function (): void {
    $private = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['in_reply_to_email_id' => $private->id]))
        ->assertHasErrors(["Email with ID [{$private->id}] not found."]);

    expect(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(0);
});

it('refuses an empty draft', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, ['connected_account_id' => $this->viewerAccount->id])
        ->assertHasErrors(['Cannot save an empty draft.']);
});

it('lists the mailbox and draft tools only for a token that holds the draft grant', function (): void {
    expect(listedToolNames($this->viewer, ['read', 'email:read']))
        ->not->toContain('create-email-draft-tool')
        ->not->toContain('list-email-accounts-tool')
        ->and(listedToolNames($this->viewer, ['read', 'email:draft']))
        ->toContain('create-email-draft-tool', 'list-email-accounts-tool')
        ->not->toContain('list-emails-tool');
});

it('leaves the signature out of a draft that has no body', function (): void {
    EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->viewerAccount->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]);

    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, array_diff_key(draftArguments($this->viewerAccount), ['body' => true]));

    expect(Email::query()->with('body')->findOrFail($data['id'])->body->body_html)
        ->not->toContain('data-id="'.SignatureBlock::ID.'"');
});

it('refuses blank recipients in a draft', function (array $overrides): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['subject' => null, 'body' => null, ...$overrides]))
        ->assertHasErrors();

    expect(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(0);
})->with([
    'empty to' => [['to' => ['']]],
    'blank to' => [['to' => [' ']]],
    'empty cc' => [['cc' => ['']]],
    'empty bcc' => [['bcc' => ['']]],
]);

it('refuses an address over 255 characters in a draft', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['to' => [str_repeat('a', 250).'@acme.test']]))
        ->assertHasErrors(['The to.0 field must not be greater than 255 characters.']);

    expect(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(0);
});

it('does not turn an unrelated runtime failure into a message for the client when drafting', function (): void {
    config(['app.debug' => false]);
    Email::creating(fn (): never => throw new ModelNotFoundException('internal detail'));

    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($this->viewerAccount))
        ->assertHasErrors(['An internal server error occurred.']);

    expect(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(0);
});

it('replaces markdown images with their alt text in assistant bodies', function (string $image): void {
    $arguments = ['body' => "See {$image} and [site](https://acme.test)"];

    $draft = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, $arguments));
    $sent = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, $arguments));

    foreach ([$draft['id'], $sent['id']] as $id) {
        expect(Email::query()->with('body')->findOrFail($id)->body->body_html)
            ->not->toContain('<img')
            ->not->toContain('attacker.test')
            ->not->toContain('data:image')
            ->toContain('See chart and')
            ->toContain('<a href="https://acme.test">site</a>');
    }
})->with([
    'remote image' => ['![chart](https://attacker.test/p.png?d=secret)'],
    'data image' => ['![chart](data:image/png;base64,iVBORw0KGgo=)'],
]);

it('caps the nesting of a deeply nested body', function (): void {
    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, [
        'body' => str_repeat('>', 49_999).'x',
    ]));

    $html = Email::query()->with('body')->findOrFail($data['id'])->body->body_html;

    expect($data['status'])->toBe('draft')
        ->and(substr_count($html, '<blockquote'))->toBeLessThanOrEqual(20);
});

function toolCallOverHttp(User $user, array $abilities, string $tool, array $arguments): TestResponse
{
    auth()->forgetGuards();

    return test()
        ->withToken($user->createToken('call-'.Str::random(6), $abilities)->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
}

it('refuses a draft call from a token without the draft grant', function (): void {
    $response = toolCallOverHttp($this->viewer, ['read', 'email:read'], 'create-email-draft-tool', draftArguments($this->viewerAccount));

    expect($response->json('error.message'))->toBe('Tool [create-email-draft-tool] not found.')
        ->and(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(0);
});

it('refuses a mailbox list call from a token without a draft or send grant', function (): void {
    $response = toolCallOverHttp($this->viewer, ['read', 'email:read'], 'list-email-accounts-tool', []);

    expect($response->json('error.message'))->toBe('Tool [list-email-accounts-tool] not found.')
        ->and($response->json('result'))->toBeNull();
});

it('lists the mailbox tool but not the draft tool for a token that holds only the send grant', function (): void {
    expect(listedToolNames($this->viewer, ['read', 'email:send']))
        ->toContain('list-email-accounts-tool')
        ->not->toContain('create-email-draft-tool');
});

it('reads an html-only email whose style block is very large', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    EmailBody::query()->create([
        'email_id' => $email->id,
        'body_text' => null,
        'body_html' => '<html><head><title>Ignored title</title><style>'.str_repeat('a{b:c}', 300_000).'</style></head><body><p>Real message</p></body></html>',
    ]);

    expect(fetchedEmail($this->viewer, $email->id)['body_text'])->toBe('Real message');
});

it('keeps style and script source out of the text whatever the closing tag looks like', function (string $html): void {
    $email = ($this->emailFrom)($this->viewer);

    EmailBody::query()->create(['email_id' => $email->id, 'body_text' => null, 'body_html' => $html]);

    expect(fetchedEmail($this->viewer, $email->id)['body_text'])
        ->toContain('Hi')
        ->not->toContain('color:red')
        ->not->toContain('alert(');
})->with([
    'space before the closing bracket' => '<p>Hi</p><style>p{color:red}</style ><p>Body</p>',
    'unclosed style' => '<p>Hi</p><style>p{color:red}',
    'uppercase script' => '<p>Hi</p><SCRIPT>alert(1)</SCRIPT><p>Body</p>',
]);

it('separates table cells and line breaks in derived text', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    EmailBody::query()->create([
        'email_id' => $email->id,
        'body_text' => null,
        'body_html' => '<table><tr><th>Name</th><th>Amount</th></tr><tr><td>Acme</td><td>$40</td></tr></table>First<br clear="all">Second&nbsp;&nbsp;line',
    ]);

    expect(fetchedEmail($this->viewer, $email->id)['body_text'])->toBe("Name Amount\nAcme $40\nFirst\nSecond line");
});

function sendArguments(ConnectedAccount $account, array $overrides = []): array
{
    return [
        'connected_account_id' => $account->getKey(),
        'to' => ['client@acme.test'],
        'subject' => 'Next steps',
        'body' => "Hi Dana,\n\nHere is the **plan**.",
        ...$overrides,
    ];
}

function memberWithRole(Workspace $workspace, WorkspaceRole $role): User
{
    $user = User::factory()->create();
    $workspace->users()->attach($user, ['role' => $role->value]);
    $user->switchWorkspace($workspace);

    return $user->fresh();
}

function mailboxOf(User $user, Workspace $workspace): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]));
}

function sendOverHttp(User $user, array $abilities, string $tokenName, array $arguments): void
{
    auth()->forgetGuards();

    $response = test()
        ->withToken($user->createToken($tokenName, $abilities)->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'send-email-tool', 'arguments' => $arguments],
        ])
        ->assertOk();

    expect($response->json('error'))->toBeNull()
        ->and($response->json('result.isError'))->not->toBeTrue();
}

it('queues the email and holds it for the configured window', function (): void {
    $this->travelTo(now()->startOfSecond());

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, ['cc' => ['boss@acme.test']]));

    $email = Email::query()->with(['body', 'participants'])->findOrFail($data['id']);

    expect($data['status'])->toBe('queued')
        ->and($data['scheduled_for'])->toBe(now()->addSeconds(300)->toIso8601String())
        ->and($email->status)->toBe(EmailStatus::QUEUED)
        ->and($email->scheduled_for->equalTo(now()->addSeconds(300)))->toBeTrue()
        ->and($email->creation_source)->toBe(EmailCreationSource::MCP)
        ->and($email->user_id)->toBe($this->viewer->id)
        ->and($email->body->body_html)->toContain('<strong>plan</strong>')
        ->and($email->participants->where('role', EmailParticipantRole::TO)->pluck('email_address')->all())->toBe(['client@acme.test'])
        ->and($email->participants->where('role', EmailParticipantRole::CC)->pluck('email_address')->all())->toBe(['boss@acme.test']);
});

it('follows the hold length from config', function (): void {
    config(['email-integration.outbox.agent_send_hold_seconds' => 60]);
    $this->travelTo(now()->startOfSecond());

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect(Email::query()->findOrFail($data['id'])->scheduled_for->equalTo(now()->addSeconds(60)))->toBeTrue();
});

it('releases a held email to the dispatcher only after the hold passes', function (): void {
    Bus::fake();
    $this->travelTo(now()->startOfSecond());

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    Bus::assertNotDispatched(SendEmailJob::class);
    expect(Email::query()->findOrFail($data['id'])->status)->toBe(EmailStatus::QUEUED);

    $this->travel(301)->seconds();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    Bus::assertDispatched(SendEmailJob::class, 1);
    expect(Email::query()->findOrFail($data['id'])->status)->toBe(EmailStatus::SENDING);
});

it('shows a held email in the default view of its owner outbox', function (): void {
    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));
    $held = Email::query()->findOrFail($data['id']);

    $this->actingAs($this->viewer);
    Filament::setTenant($this->workspace);

    livewire(OutboxTable::class)
        ->assertCanSeeTableRecords([$held])
        ->filterTable('status_tab', 'scheduled')
        ->assertCanNotSeeTableRecords([$held]);
});

it('schedules email sent through rela like composer mail in the owner outbox', function (): void {
    $this->travelTo(now()->startOfSecond());

    $inUndoWindow = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'connected_account_id' => $this->viewerAccount->getKey(),
        'status' => EmailStatus::QUEUED,
        'creation_source' => EmailCreationSource::CHAT,
        'scheduled_for' => now()->addSeconds(3),
    ]);

    $scheduled = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'connected_account_id' => $this->viewerAccount->getKey(),
        'status' => EmailStatus::QUEUED,
        'creation_source' => EmailCreationSource::CHAT,
        'scheduled_for' => now()->addHour(),
    ]);

    $this->actingAs($this->viewer);
    Filament::setTenant($this->workspace);

    livewire(OutboxTable::class)
        ->assertCanSeeTableRecords([$inUndoWindow])
        ->assertCanNotSeeTableRecords([$scheduled])
        ->filterTable('status_tab', 'scheduled')
        ->assertCanSeeTableRecords([$scheduled])
        ->assertCanNotSeeTableRecords([$inUndoWindow]);
});

it('sends with the sender default sharing level', function (): void {
    $this->viewerAccount->forceFill(['sharing_tier' => EmailPrivacyTier::SUBJECT])->save();

    $data = emailToolData($this->viewer->fresh(), SendEmailTool::class, sendArguments($this->viewerAccount));

    expect(Email::query()->findOrFail($data['id'])->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT);
});

it('keeps a sent email on its mailbox level when the owner lowers it later', function (): void {
    $this->viewerAccount->forceFill(['sharing_tier' => EmailPrivacyTier::FULL])->save();

    $data = emailToolData($this->viewer->fresh(), SendEmailTool::class, sendArguments($this->viewerAccount));

    resolve(SaveMailboxSharingTierAction::class)->execute($this->viewer, $this->viewerAccount->fresh(), EmailPrivacyTier::PRIVATE);

    $sent = Email::query()->findOrFail($data['id']);

    expect($sent->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE)
        ->and($sent->privacy_tier_customized)->toBeFalse();
});

it('expands the default signature into the sent body', function (): void {
    EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->viewerAccount->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'content_html' => '<p>Dana, Acme</p>',
    ]);

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect(Email::query()->with('body')->findOrFail($data['id'])->body->body_html)
        ->toContain('<p>Dana, Acme</p>')
        ->not->toContain('data-type="customBlock"');
});

it('leaves the signature out of a sent body when asked to', function (mixed $flag): void {
    EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->viewerAccount->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'content_html' => '<p>Dana, Acme</p>',
    ]);

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, ['include_signature' => $flag]));

    expect(Email::query()->with('body')->findOrFail($data['id'])->body->body_html)->not->toContain('Dana, Acme');
})->with([
    'false' => false,
    'zero' => 0,
    'zero string' => '0',
]);

it('escapes raw html in a sent body', function (): void {
    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, [
        'body' => 'Hi <script>alert(1)</script> <img src=x onerror=alert(1)> **safe**',
    ]));

    expect(Email::query()->with('body')->findOrFail($data['id'])->body->body_html)
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->toContain('<strong>safe</strong>');
});

it('keeps merge tags literal in a sent body', function (): void {
    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, [
        'body' => 'Dated {today} and {{ today }} and {first_name}',
    ]));

    $html = Email::query()->with('body')->findOrFail($data['id'])->body->body_html;

    expect(html_entity_decode(strip_tags($html)))->toBe('Dated {today} and {{ today }} and {first_name}')
        ->and($html)->not->toContain(now()->toFormattedDateString());
});

it('fills a merge tag in the mailbox signature while the body keeps its own literal', function (): void {
    EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->viewerAccount->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'content_html' => '<p>Dana, {today}</p>',
    ]);

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, ['body' => 'Dated {today}']));

    expect(Email::query()->with('body')->findOrFail($data['id'])->body->body_html)
        ->toContain('Dated {today}')
        ->toContain('<p>Dana, '.now()->toFormattedDateString().'</p>');
});

it('keeps a link whose address holds braces working in a sent body', function (): void {
    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, [
        'body' => 'Read [the docs](https://acme.test/a/{id}) now',
    ]));

    expect(Email::query()->with('body')->findOrFail($data['id'])->body->body_html)
        ->toContain('<a href="https://acme.test/a/%7Bid%7D">the docs</a>')
        ->not->toContain(now()->toFormattedDateString());
});

it('tells the user an email is held, with a way to cancel it', function (): void {
    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    $notification = $this->viewer->notifications()->sole();

    expect($notification->data['body'])->toContain('Next steps')->toContain('client@acme.test')->toContain('5 minutes')
        ->and(collect($notification->data['actions'])->pluck('name')->all())->toContain('cancelSend')
        ->and(json_encode($notification->data['actions']))->toContain($data['id']);
});

it('stores the held notice at once, without waiting for the queue', function (): void {
    Queue::fake();

    emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect($this->viewer->notifications()->count())->toBe(1);
});

it('names the connection in the notice from the token name', function (): void {
    sendOverHttp($this->viewer, ['read', 'email:send'], 'Desk Agent', sendArguments($this->viewerAccount));

    expect($this->viewer->notifications()->sole()->data['title'])->toBe('Desk Agent queued an email');
});

it('names the connection in the notice from the oauth client', function (): void {
    $client = Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => 'Claude Desktop',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code'],
        'revoked' => false,
        'owner_type' => $this->viewer->getMorphClass(),
        'owner_id' => $this->viewer->getKey(),
    ]);

    Passport::actingAs($this->viewer, ['mcp:use', 'email:send'], client: $client);

    emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect($this->viewer->notifications()->sole()->data['title'])->toBe('Claude Desktop queued an email');
});

it('falls back to a generic name when the connection has none', function (): void {
    emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect($this->viewer->notifications()->sole()->data['title'])->toBe('An AI assistant queued an email');
});

it('cancels a held email from the notification', function (): void {
    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    $this->actingAs($this->viewer);
    Filament::setTenant($this->workspace);

    livewire(EmailAccessNotificationHandler::class)
        ->dispatch('undo-queued-send', emailId: $data['id']);

    expect(Email::query()->findOrFail($data['id'])->status)->toBe(EmailStatus::CANCELLED);
});

it('threads a reply onto an email the caller may view', function (): void {
    $original = ($this->emailFrom)($this->viewer, ['rfc_message_id' => '<orig@acme.test>']);

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, ['in_reply_to_email_id' => $original->id]));

    expect(Email::query()->findOrFail($data['id'])->in_reply_to)->toBe('<orig@acme.test>');
});

it('refuses a reply aimed at an email the caller may not view', function (): void {
    $private = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount, ['in_reply_to_email_id' => $private->id]))
        ->assertHasErrors(["Email with ID [{$private->id}] not found."]);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('answers a missing reply target the same way as a hidden one', function (): void {
    $missing = (string) Str::ulid();

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount, ['in_reply_to_email_id' => $missing]))
        ->assertHasErrors(["Email with ID [{$missing}] not found."]);
});

it('refuses to send from a mailbox the caller does not own or that cannot send', function (): void {
    $receiveOnly = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->withoutSend()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]));

    foreach ([$this->coworkerAccount, $receiveOnly] as $account) {
        RelaticleServer::actingAs($this->viewer)
            ->tool(SendEmailTool::class, sendArguments($account))
            ->assertHasErrors(['Pick one of your own mailboxes that can send. List your mailboxes to find one.']);
    }

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('refuses to send from the caller own mailbox in another workspace', function (): void {
    $elsewhere = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'user_id' => $this->viewer->id,
    ]));

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($elsewhere))
        ->assertHasErrors(['Pick one of your own mailboxes that can send. List your mailboxes to find one.']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('refuses to send from a mailbox that needs reconnecting', function (EmailAccountStatus $status): void {
    $this->viewerAccount->update(['status' => $status]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount))
        ->assertHasErrors(['Pick one of your own mailboxes that can send. List your mailboxes to find one.']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
})->with([
    'reauth required' => EmailAccountStatus::REAUTH_REQUIRED,
    'error' => EmailAccountStatus::ERROR,
]);

it('refuses a reply aimed at the caller own draft', function (): void {
    $draft = ($this->emailFrom)($this->viewer, ['status' => EmailStatus::DRAFT]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount, ['in_reply_to_email_id' => $draft->id]))
        ->assertHasErrors(["Email with ID [{$draft->id}] not found."]);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('follows the workspace an unpinned token names in a header for the send role', function (): void {
    $other = Workspace::factory()->create();
    $other->users()->attach($this->viewer, ['role' => WorkspaceRole::Viewer->value]);

    expect(listedToolNamesIn($this->viewer, ['read', 'email:send'], $this->workspace))->toContain('send-email-tool')
        ->and(listedToolNamesIn($this->viewer, ['read', 'email:send'], $other))->not->toContain('send-email-tool');
});

it('requires a recipient, a subject and a body', function (array $missing): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, array_diff_key(sendArguments($this->viewerAccount), array_flip($missing)))
        ->assertHasErrors();

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
})->with([
    'no recipient' => [['to']],
    'no subject' => [['subject']],
    'no body' => [['body']],
]);

it('rejects empty and wrongly typed arguments', function (array $overrides): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount, $overrides))
        ->assertHasErrors();

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
})->with([
    'empty subject' => [['subject' => '']],
    'blank body' => [['body' => '   ']],
    'empty recipient list' => [['to' => []]],
    'recipient as a string' => [['to' => 'client@acme.test']],
    'invalid recipient' => [['to' => ['not-an-address']]],
    'too many recipients' => [['to' => array_map(fn (int $number): string => "person{$number}@acme.test", range(1, 21))]],
    'subject as a list' => [['subject' => ['Next steps']]],
    'invalid cc' => [['cc' => ['nope']]],
    'blank recipient' => [['to' => [' ']]],
    'signature flag as text' => [['include_signature' => 'maybe']],
    'body over the limit' => [['body' => str_repeat('a', 50001)]],
]);

it('refuses an address over 255 characters in a send', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount, ['to' => [str_repeat('a', 250).'@acme.test']]))
        ->assertHasErrors(['The to.0 field must not be greater than 255 characters.']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('reports the outbox cap instead of queuing past it', function (): void {
    config(['email-integration.outbox.max_queued_per_user' => 0]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount))
        ->assertHasErrors(['You have 0 emails queued. Clear the outbox before queuing more.']);
});

it('does not turn an unrelated runtime failure into a message for the client', function (): void {
    config(['app.debug' => false]);
    Email::creating(fn (): never => throw new ModelNotFoundException('internal detail'));

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount))
        ->assertHasErrors(['An internal server error occurred.']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('keeps the send tool from a viewer who holds the send grant', function (): void {
    $viewer = memberWithRole($this->workspace, WorkspaceRole::Viewer);
    $mailbox = mailboxOf($viewer, $this->workspace);

    expect(listedToolNames($viewer, ['read', 'email:send']))->not->toContain('send-email-tool');

    RelaticleServer::actingAs($viewer)
        ->tool(SendEmailTool::class, sendArguments($mailbox))
        ->assertHasErrors(['Tool [send-email-tool] not found.']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('refuses the action itself to a user whose role may not send', function (): void {
    $viewer = memberWithRole($this->workspace, WorkspaceRole::Viewer);
    $mailbox = mailboxOf($viewer, $this->workspace);

    expect(fn () => resolve(QueueAgentEmailAction::class)->execute($viewer, [
        'connected_account_id' => (string) $mailbox->getKey(),
        'to' => ['client@acme.test'],
        'subject' => 'Next steps',
        'body' => 'Hello',
    ], EmailCreationSource::MCP, 'Desk Agent'))->toThrow(HttpException::class);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('refuses the action itself to a user with no current workspace', function (): void {
    $user = User::factory()->create();

    expect(fn () => resolve(QueueAgentEmailAction::class)->execute($user, [
        'connected_account_id' => (string) $this->viewerAccount->getKey(),
        'to' => ['client@acme.test'],
        'subject' => 'Next steps',
        'body' => 'Hello',
    ], EmailCreationSource::MCP, 'Desk Agent'))->toThrow(HttpException::class);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('takes the send tool away when a member is demoted to viewer', function (): void {
    $member = memberWithRole($this->workspace, WorkspaceRole::Member);

    expect(listedToolNames($member, ['read', 'email:send']))->toContain('send-email-tool');

    $this->workspace->users()->updateExistingPivot($member->id, ['role' => WorkspaceRole::Viewer->value]);

    expect(listedToolNames($member->fresh(), ['read', 'email:send']))->not->toContain('send-email-tool');
});

it('lists the send tool only for a token that holds the send grant', function (): void {
    expect(listedToolNames($this->viewer, ['read', 'email:read', 'email:draft']))->not->toContain('send-email-tool')
        ->and(listedToolNames($this->viewer, ['read', 'email:send']))->toContain('send-email-tool', 'list-email-accounts-tool');
});

it('lists no email tool while the email feature is off', function (): void {
    Feature::define(EmailIntegration::class, false);

    expect(array_values(array_filter(
        listedToolNames($this->viewer, ['read', 'email:read', 'email:draft', 'email:send']),
        fn (string $name): bool => str_contains($name, 'email'),
    )))->toBe([]);
});

it('cancels a held email from another workspace of the same user', function (): void {
    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    $elsewhere = Workspace::factory()->create();
    $elsewhere->users()->attach($this->viewer, ['role' => WorkspaceRole::Member->value]);
    $this->viewer->switchWorkspace($elsewhere);

    $this->actingAs($this->viewer->fresh());
    Filament::setTenant($elsewhere);

    livewire(EmailAccessNotificationHandler::class)
        ->dispatch('undo-queued-send', emailId: $data['id']);

    expect(Email::query()->findOrFail($data['id'])->status)->toBe(EmailStatus::CANCELLED);
});

it('escapes the subject and the connection name in the notice', function (): void {
    sendOverHttp($this->viewer, ['read', 'email:send'], '<b>Bot</b>', sendArguments($this->viewerAccount, [
        'subject' => 'Lunch<span style="display:none">',
    ]));

    $data = $this->viewer->notifications()->sole()->data;

    expect($data['title'])->toContain('&lt;b&gt;')->not->toContain('<b>')
        ->and($data['body'])->toContain('&lt;span')->not->toContain('<span');
});

it('escapes every recipient in the notice', function (): void {
    emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, ['to' => ['"<i>x</i>"@acme.test']]));

    expect($this->viewer->notifications()->sole()->data['body'])->toContain('&lt;i&gt;')->not->toContain('<i>');
});

it('names every recipient in the notice', function (): void {
    emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, [
        'to' => ['self@acme.test'],
        'cc' => ['boss@acme.test'],
        'bcc' => ['outsider@other.test'],
    ]));

    expect($this->viewer->notifications()->sole()->data['body'])
        ->toContain('self@acme.test')
        ->toContain('boss@acme.test')
        ->toContain('outsider@other.test');
});

it('names the workspace in the notice', function (): void {
    emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect($this->viewer->notifications()->sole()->data['body'])->toContain(e($this->workspace->name));
});

it('says one minute for a hold of sixty seconds', function (): void {
    config(['email-integration.outbox.agent_send_hold_seconds' => 60]);

    emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect($this->viewer->notifications()->sole()->data['body'])->toContain('in 1 minute.');
});

it('says five minutes for the default hold', function (): void {
    emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect($this->viewer->notifications()->sole()->data['body'])->toContain('in 5 minutes.');
});

it('rounds the minutes in the notice down', function (): void {
    config(['email-integration.outbox.agent_send_hold_seconds' => 150]);

    emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect($this->viewer->notifications()->sole()->data['body'])->toContain('in 2 minutes.');
});

it('holds for at least a minute whatever the config says', function (int $configured): void {
    config(['email-integration.outbox.agent_send_hold_seconds' => $configured]);
    $this->travelTo(now()->startOfSecond());

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect(Email::query()->findOrFail($data['id'])->scheduled_for->equalTo(now()->addSeconds(60)))->toBeTrue()
        ->and($this->viewer->notifications()->sole()->data['body'])->toContain('in 1 minute.');
})->with([
    'zero' => 0,
    'negative' => -5,
]);

it('cancels the email when the notice cannot be stored', function (): void {
    config(['app.debug' => false]);
    DatabaseNotification::creating(fn (): never => throw new RuntimeException('notifications down'));

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount))
        ->assertHasErrors(['An internal server error occurred.']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0)
        ->and(Email::query()->where('status', EmailStatus::CANCELLED)->count())->toBe(1);
});

it('stops an assistant from holding more than ten emails at once', function (): void {
    foreach (range(1, 10) as $number) {
        Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->viewer->id,
            'connected_account_id' => $this->viewerAccount->getKey(),
            'status' => EmailStatus::QUEUED,
            'creation_source' => EmailCreationSource::MCP,
            'scheduled_for' => now()->addMinutes(5),
        ]);
    }

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount))
        ->assertHasErrors(['The user already has 10 assistant emails waiting to send. Wait until they send or the user cancels them.']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(10);
});

it('does not count the user own queued mail toward the assistant cap', function (): void {
    foreach (range(1, 10) as $number) {
        Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->viewer->id,
            'connected_account_id' => $this->viewerAccount->getKey(),
            'status' => EmailStatus::QUEUED,
            'creation_source' => EmailCreationSource::COMPOSE,
            'scheduled_for' => now()->addMinutes(5),
        ]);
    }

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect($data['status'])->toBe('queued');
});

it('does not count email sent through rela toward the ten held assistant emails', function (): void {
    foreach (range(1, 10) as $number) {
        Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->viewer->id,
            'connected_account_id' => $this->viewerAccount->getKey(),
            'status' => EmailStatus::QUEUED,
            'creation_source' => EmailCreationSource::CHAT,
            'scheduled_for' => now()->addSeconds(5),
        ]);
    }

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect($data['status'])->toBe('queued');
});

it('refuses more than twenty recipients across to, cc and bcc', function (): void {
    $addresses = fn (string $prefix, int $count): array => array_map(fn (int $number): string => "{$prefix}{$number}@acme.test", range(1, $count));

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount, [
            'to' => $addresses('to', 10),
            'cc' => $addresses('cc', 6),
            'bcc' => $addresses('bcc', 5),
        ]))
        ->assertHasErrors(['An email can go to at most 20 recipients in total across to, cc and bcc.']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('names the total limit when one list alone holds more than twenty recipients', function (string $field): void {
    $addresses = array_map(fn (int $number): string => "person{$number}@acme.test", range(1, 21));

    RelaticleServer::actingAs($this->viewer)
        ->tool(SendEmailTool::class, sendArguments($this->viewerAccount, [$field => $addresses]))
        ->assertHasErrors(['An email can go to at most 20 recipients in total across to, cc and bcc.']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
})->with(['to', 'cc', 'bcc']);

it('accepts twenty recipients across to, cc and bcc', function (): void {
    $addresses = fn (string $prefix, int $count): array => array_map(fn (int $number): string => "{$prefix}{$number}@acme.test", range(1, $count));

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount, [
        'to' => $addresses('to', 10),
        'cc' => $addresses('cc', 5),
        'bcc' => $addresses('bcc', 5),
    ]));

    expect($data['status'])->toBe('queued');
});

it('gives a wildcard token no email tool', function (): void {
    expect(array_values(array_filter(
        listedToolNames($this->viewer, ['*']),
        fn (string $name): bool => str_contains($name, 'email'),
    )))->toBe([]);
});
