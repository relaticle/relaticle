<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Features\EmailConversations;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\EmailsRelationManager as CompanyEmailsRelationManager;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Controllers\OpenEmailController;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;

use function Pest\Laravel\actingAs;

mutates(OpenEmailController::class, EmailAccessNotificationHandler::class, EmailInboxPage::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);

    $this->ownerMailbox = conversationsRouteMailbox($this->owner, 'ana@acme.test');
    $this->teammateMailbox = conversationsRouteMailbox($this->teammate, 'ben@acme.test');
    $this->company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);

    Feature::for($this->workspace)->activate(EmailConversations::class);
    actingAs($this->owner);
});

function conversationsRouteMailbox(User $user, string $address): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'user_id' => $user->id,
        'email_address' => $address,
        'status' => 'active',
    ]));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function conversationsRouteMessage(ConnectedAccount $mailbox, string $key, string $from, array $attributes = []): Email
{
    $email = Email::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'user_id' => $mailbox->user_id,
        'connected_account_id' => $mailbox->id,
        'conversation_key' => $key,
        'rfc_message_id' => '<'.Str::lower((string) Str::ulid()).'@mail.test>',
        'direction' => $from === $mailbox->email_address ? EmailDirection::OUTBOUND : EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'is_internal' => false,
        'sent_at' => now(),
        ...$attributes,
    ]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->id,
        'email_address' => $from,
        'name' => Str::headline(Str::before($from, '@')),
    ]);

    resolve(RefreshConversationSummary::class)->execute($mailbox->workspace_id, $key);

    return $email;
}

it('opens a linked email on the first linked company Emails tab', function (): void {
    $email = conversationsRouteMessage($this->ownerMailbox, 'conv-route', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);

    $relation = (string) array_search(CompanyEmailsRelationManager::class, CompanyResource::getRelations(), true);

    $this->get(route('email.open', $email))
        ->assertRedirect(CompanyResource::getUrl('view', ['record' => $this->company, 'relation' => $relation, 'email' => $email->id], tenant: $this->workspace));
});

it('opens an unlinked email on the Emails page', function (): void {
    $email = conversationsRouteMessage($this->ownerMailbox, 'conv-loose', 'zoe@figma.test');

    $this->get(route('email.open', $email))
        ->assertRedirect(EmailInboxPage::getUrl(['view' => 'all', 'email' => $email->id], tenant: $this->workspace));
});

it('opens the viewer own copy when a link names a teammate copy of the same message', function (): void {
    $bens = conversationsRouteMessage($this->teammateMailbox, 'conv-both', 'brian@airbnb.test', ['rfc_message_id' => '<both@airbnb.test>']);
    $mine = conversationsRouteMessage($this->ownerMailbox, 'conv-both', 'brian@airbnb.test', ['rfc_message_id' => '<both@airbnb.test>']);

    $this->get(route('email.open', $bens))
        ->assertRedirectContains("email={$mine->id}");
});

it('answers missing, private, unlinked teammate and foreign mail with the same not found page', function (): void {
    $private = conversationsRouteMessage($this->teammateMailbox, 'conv-private', 'olivia@stripe.test', [
        'subject' => 'Private compensation thread',
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
    ]);
    $this->company->emails()->attach($private->id);
    $unlinked = conversationsRouteMessage($this->teammateMailbox, 'conv-unlinked', 'olivia@stripe.test', ['subject' => 'Ben side project']);
    $stranger = User::factory()->withWorkspace()->create();
    $foreign = conversationsRouteMessage(conversationsRouteMailbox($stranger, 'sam@other.test'), 'conv-foreign', 'x@y.test', ['subject' => 'Other workspace deal']);

    foreach ([(string) Str::ulid(), $private->id, $unlinked->id, $foreign->id] as $id) {
        $this->get(route('email.open', $id))
            ->assertNotFound()
            ->assertDontSee('Private compensation thread')
            ->assertDontSee('Ben side project')
            ->assertDontSee('Other workspace deal');
    }
});

it('keeps the old dashboard links while conversations are off, with the reader only for a readable body', function (): void {
    Feature::for($this->workspace)->deactivate(EmailConversations::class);
    $email = conversationsRouteMessage($this->ownerMailbox, 'conv-route', 'brian@airbnb.test');
    $locked = conversationsRouteMessage($this->teammateMailbox, 'conv-locked', 'olivia@airbnb.test', ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);
    $this->company->emails()->attach($locked->id);

    $this->get(route('email.open', $email))
        ->assertRedirect(Dashboard::getUrl(['email' => $email->id], panel: 'app', tenant: $this->workspace));

    $this->get(route('email.open', $locked))
        ->assertRedirect(Dashboard::getUrl(panel: 'app', tenant: $this->workspace));
});

it('opens a link from a workspace the viewer is not switched into without switching it', function (): void {
    $other = User::factory()->withWorkspace()->create();
    $otherWorkspace = $other->currentWorkspace;
    $otherWorkspace->users()->attach($this->owner, ['role' => 'member']);
    Feature::for($otherWorkspace)->activate(EmailConversations::class);
    $email = conversationsRouteMessage(conversationsRouteMailbox($other, 'kim@beta.test'), 'conv-beta', 'lee@linear.test', ['privacy_tier' => EmailPrivacyTier::FULL]);
    Company::factory()->create(['workspace_id' => $otherWorkspace->id])->emails()->attach($email->id);

    $this->get(route('email.open', $email))
        ->assertRedirectContains("email={$email->id}");

    expect($this->owner->refresh()->current_workspace_id)->toBe($this->workspace->id);
});

it('renders one reader when the Emails page opens a resolved link', function (): void {
    $email = conversationsRouteMessage($this->ownerMailbox, 'conv-loose', 'zoe@figma.test');

    $html = $this->followingRedirects()->get(route('email.open', $email))->assertOk()->getContent();

    expect(substr_count($html, 'fi-email-reader-drawer'))->toBe(1)
        ->and(substr_count($html, 'fi-email-reader-panel'))->toBe(0);
});

it('leaves a resolved link to the record Emails tab instead of the panel reader', function (bool $conversations, int $panelReaders): void {
    $email = conversationsRouteMessage($this->ownerMailbox, 'conv-route', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);
    $relation = (string) array_search(CompanyEmailsRelationManager::class, CompanyResource::getRelations(), true);

    if (! $conversations) {
        Feature::for($this->workspace)->deactivate(EmailConversations::class);
    }

    $html = $this->get(CompanyResource::getUrl('view', ['record' => $this->company, 'relation' => $relation, 'email' => $email->id], tenant: $this->workspace))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'fi-email-reader-panel'))->toBe($panelReaders);
})->with([
    'conversations on' => [true, 0],
    'conversations off' => [false, 1],
]);

it('skips a linked record whose Emails tab hides the mailbox', function (): void {
    $domains = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'company')
        ->where('code', CompanyField::DOMAINS->value)
        ->firstOrFail();
    $this->company->saveCustomFieldValue($domains, ['https://secret.example'], $this->workspace);
    Feature::for($this->workspace)->activate(EmailConversations::class);
    WorkspaceEmailBlocklist::factory()->protected()->domain('secret.example')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
    ]);
    $email = conversationsRouteMessage($this->ownerMailbox, 'conv-secret', 'brian@secret.example');
    $this->company->emails()->attach($email->id);

    $this->get(route('email.open', $email))
        ->assertRedirect(EmailInboxPage::getUrl(['view' => 'all', 'email' => $email->id], tenant: $this->workspace));

    $open = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Stripe']);
    $open->emails()->attach($email->id);
    $relation = (string) array_search(CompanyEmailsRelationManager::class, CompanyResource::getRelations(), true);

    $this->get(route('email.open', $email))
        ->assertRedirect(CompanyResource::getUrl('view', ['record' => $open, 'relation' => $relation, 'email' => $email->id], tenant: $this->workspace));
});

it('answers a hidden or trashed email with not found while conversations are off', function (): void {
    Feature::for($this->workspace)->deactivate(EmailConversations::class);
    $private = conversationsRouteMessage($this->teammateMailbox, 'conv-private', 'olivia@stripe.test', ['privacy_tier' => EmailPrivacyTier::PRIVATE]);
    $this->company->emails()->attach($private->id);
    $trashed = conversationsRouteMessage($this->ownerMailbox, 'conv-trashed', 'brian@airbnb.test');
    $trashed->delete();

    $this->get(route('email.open', $private))->assertNotFound();
    $this->get(route('email.open', $trashed))->assertNotFound();
});

it('follows a link to a locked email to the gate with its subject hidden everywhere', function (): void {
    $locked = conversationsRouteMessage($this->teammateMailbox, 'conv-locked', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($locked->id);

    $target = (string) $this->get(route('email.open', $locked))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    $html = (string) $this->followingRedirects()->get(route('email.open', $locked))->assertOk()->getContent();
    preg_match('/<title>(.*?)<\\/title>/s', $html, $title);

    expect($query['email'] ?? null)->toBe($locked->id)
        ->and($html)->not->toContain('Contract redlines')
        ->and($title[1] ?? '')->toContain('Airbnb')->not->toContain('Contract redlines');

    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);

    Livewire::withQueryParams($query)
        ->test(CompanyEmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSeeHtml("data-reader-gate=\"{$locked->id}\"")
        ->assertSee(__('filament/pages/email-inbox.subject.hidden'))
        ->assertDontSee('Contract redlines');
});
