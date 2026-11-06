<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\LinkEmailToRecord;
use Relaticle\EmailIntegration\Actions\PrepareEmailRecordLink;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Actions\UnlinkEmailFromRecord;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Jobs\RecomputeRecordEmailMetricsJob;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLinkSuppression;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(
    LinkEmailToRecord::class,
    UnlinkEmailFromRecord::class,
    PrepareEmailRecordLink::class,
    RecordCommunicationMetrics::class,
    RecomputeRecordEmailMetricsJob::class,
    HasEmailReaderActions::class,
);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);
    $this->company = Company::factory()->for($this->workspace)->create(['name' => 'Airbnb']);

    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
});

function linkingEmail(User $owner, array $attributes = []): Email
{
    $mailbox = ConnectedAccount::query()
        ->where('workspace_id', $owner->current_workspace_id)
        ->where('user_id', $owner->getKey())
        ->first() ?? ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'workspace_id' => $owner->current_workspace_id,
            'user_id' => $owner->getKey(),
            'status' => 'active',
        ]));

    return Email::factory()->create([
        'workspace_id' => $owner->current_workspace_id,
        'user_id' => $owner->getKey(),
        'connected_account_id' => $mailbox->getKey(),
        'conversation_key' => 'airbnb-renewal',
        'rfc_message_id' => '<renewal@airbnb.test>',
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::SUBJECT,
        'is_internal' => false,
        'sent_at' => now(),
        'linked_at' => now(),
        ...$attributes,
    ]);
}

function linkSource(Email $email, Company $company): ?string
{
    $source = DB::table('emailables')
        ->where('email_id', $email->getKey())
        ->where('emailable_type', $company->getMorphClass())
        ->where('emailable_id', $company->getKey())
        ->value('link_source');

    return $source === null ? null : (string) $source;
}

it('refuses an unlink by a teammate who cannot see the email and keeps the link', function (): void {
    $email = linkingEmail($this->owner, ['privacy_tier' => EmailPrivacyTier::PRIVATE, 'privacy_tier_customized' => true]);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

    expect(fn (): mixed => resolve(UnlinkEmailFromRecord::class)->execute($this->teammate, $email, $this->company))
        ->toThrow(HttpException::class);

    expect(linkSource($email, $this->company))->toBe('auto')
        ->and(EmailLinkSuppression::query()->count())->toBe(0);
});

it('refuses to link an email to a record from another workspace', function (): void {
    $foreign = Company::factory()->for(Workspace::factory())->create();
    $email = linkingEmail($this->owner);

    expect(fn (): mixed => resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $foreign))
        ->toThrow(HttpException::class);

    expect(DB::table('emailables')->where('email_id', $email->getKey())->count())->toBe(0);
});

it('pins the copies the user reached and leaves the copies they only see as they were', function (): void {
    $own = linkingEmail($this->owner);
    $sharedCopy = linkingEmail($this->teammate, ['rfc_message_id' => '<renewal-shared@airbnb.test>']);
    EmailShare::factory()->create(['email_id' => $sharedCopy->getKey(), 'shared_with' => $this->owner->getKey(), 'shared_by' => $this->teammate->getKey()]);
    $teammateCopy = linkingEmail($this->teammate);
    $teammateCopy->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $privateCopy = linkingEmail($this->teammate, [
        'rfc_message_id' => '<renewal-reply@airbnb.test>',
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'privacy_tier_customized' => true,
    ]);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $own, $this->company);

    expect(linkSource($own, $this->company))->toBe('manual')
        ->and(linkSource($sharedCopy, $this->company))->toBe('manual')
        ->and(linkSource($teammateCopy, $this->company))->toBe('auto')
        ->and(linkSource($privateCopy, $this->company))->toBeNull();
});

it('refuses a teammate who reached no copy and leaves the owner\'s copy on the record', function (EmailPrivacyTier $tier): void {
    $owners = linkingEmail($this->owner, ['privacy_tier' => $tier, 'privacy_tier_customized' => true]);
    $owners->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $stripe = Company::factory()->for($this->workspace)->create(['name' => 'Stripe']);

    expect(fn (): mixed => resolve(UnlinkEmailFromRecord::class)->execute($this->teammate, $owners, $this->company))
        ->toThrow(ValidationException::class, 'Only people who hold this conversation')
        ->and(fn (): mixed => resolve(LinkEmailToRecord::class)->execute($this->teammate, $owners, $stripe))
        ->toThrow(ValidationException::class, 'Only people who hold this conversation');

    expect(linkSource($owners, $this->company))->toBe('auto')
        ->and(DB::table('emailables')->where('emailable_id', $stripe->getKey())->exists())->toBeFalse()
        ->and(EmailLinkSuppression::query()->count())->toBe(0);
})->with([EmailPrivacyTier::SUBJECT, EmailPrivacyTier::METADATA_ONLY]);

it('unlinks only the teammate\'s own copy when the owner\'s copy is visible to them below full', function (EmailPrivacyTier $tier): void {
    $owners = linkingEmail($this->owner, ['privacy_tier' => $tier, 'privacy_tier_customized' => true]);
    $teammates = linkingEmail($this->teammate, ['rfc_message_id' => '<renewal-cc@airbnb.test>']);
    $owners->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $teammates->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

    resolve(UnlinkEmailFromRecord::class)->execute($this->teammate, $owners, $this->company);

    expect(linkSource($owners, $this->company))->toBe('auto')
        ->and(linkSource($teammates, $this->company))->toBeNull()
        ->and(EmailLinkSuppression::query()->pluck('email_id')->all())->toBe([$teammates->getKey()]);
})->with([EmailPrivacyTier::SUBJECT, EmailPrivacyTier::METADATA_ONLY]);

it('unlinks the copies the user reached, so the conversation leaves the record for the user', function (): void {
    $own = linkingEmail($this->owner);
    $teammateCopy = linkingEmail($this->teammate);
    resolve(LinkEmailToRecord::class)->execute($this->owner, $own, $this->company);
    resolve(RefreshConversationSummary::class)->execute($this->workspace->getKey(), 'airbnb-renewal');
    $conversations = resolve(ConversationsQuery::class);
    $onCompany = fn (): array => $conversations->paginate($this->owner, ConversationView::All, ConversationFilters::forRecord($this->company))->rows;

    expect($onCompany())->toHaveCount(1)
        ->and($conversations->counts($this->owner)['unlinked'])->toBe(0);

    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $own, $this->company);

    expect($onCompany())->toBeEmpty()
        ->and($conversations->counts($this->owner)['unlinked'])->toBe(1)
        ->and(linkSource($own, $this->company))->toBeNull()
        ->and(linkSource($teammateCopy, $this->company))->toBeNull()
        ->and(EmailLinkSuppression::query()->where('user_id', $this->owner->getKey())->pluck('email_id')->all())->toBe([$own->getKey()]);
});

it('links a record again after an unlink and clears the suppression', function (): void {
    $email = linkingEmail($this->owner);
    resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $this->company);
    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $email, $this->company);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $this->company);
    resolve(LinkEmailAction::class)->reapply($email->fresh());

    expect(linkSource($email, $this->company))->toBe('manual')
        ->and(EmailLinkSuppression::query()->count())->toBe(0);
});

it('counts a newly linked email once and recounts the record after an unlink', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1)->startOfDay());
    $earlier = linkingEmail($this->owner, [
        'conversation_key' => 'airbnb-kickoff',
        'rfc_message_id' => '<kickoff@airbnb.test>',
        'direction' => EmailDirection::OUTBOUND,
        'sent_at' => now()->subDays(3),
    ]);
    $latest = linkingEmail($this->owner);
    $link = resolve(LinkEmailToRecord::class);

    $link->execute($this->owner, $earlier, $this->company);
    $link->execute($this->owner, $latest, $this->company);
    $link->execute($this->owner, $latest, $this->company);

    expect($this->company->fresh())
        ->email_count->toBe(2)
        ->inbound_email_count->toBe(1)
        ->outbound_email_count->toBe(1)
        ->last_email_at->toEqual(now());

    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $latest, $this->company);

    expect($this->company->fresh())
        ->email_count->toBe(1)
        ->inbound_email_count->toBe(0)
        ->outbound_email_count->toBe(1)
        ->last_email_at->toEqual(now()->subDays(3))
        ->last_interaction_at->toEqual(now()->subDays(3));
});

it('counts a copy once when the link comes before its own sync linking', function (): void {
    $pending = linkingEmail($this->owner, ['linked_at' => null]);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $pending, $this->company);

    expect($this->company->fresh()->email_count)->toBe(0);

    resolve(LinkEmailAction::class)->execute($pending->fresh());

    expect($this->company->fresh()->email_count)->toBe(1);
});

it('leaves undelivered copies of the conversation off the record and out of its counts', function (): void {
    $sent = linkingEmail($this->owner);
    $queued = linkingEmail($this->owner, [
        'rfc_message_id' => '<queued@airbnb.test>',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
        'sent_at' => null,
    ]);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $sent, $this->company);

    expect(linkSource($queued, $this->company))->toBeNull()
        ->and($this->company->fresh()->email_count)->toBe(1);
});

function linkingPage(Email $email): mixed
{
    config(['relaticle.features.email_conversations' => [$email->workspace_id]]);
    resolve(RefreshConversationSummary::class)->execute($email->workspace_id, (string) $email->conversation_key);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    return livewire(EmailInboxPage::class)->call('selectEmail', $email->getKey());
}

it('links the open conversation from the drawer picker as a pin', function (): void {
    $email = linkingEmail($this->owner);

    linkingPage($email)
        ->assertSeeHtml('data-reader-action="link"')
        ->mountAction('linkRecord', arguments: ['emailId' => $email->getKey()])
        ->setActionData(['target_type' => 'company', 'target_id' => $this->company->getKey()])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Linked to Airbnb')
        ->assertSeeHtml('data-linked-record="company:'.$this->company->getKey().'"');

    expect(linkSource($email, $this->company))->toBe('manual');
});

it('shows a chip for every record the open conversation is on, past the three a list row reads', function (): void {
    $email = linkingEmail($this->owner);
    $records = [
        $this->company,
        Company::factory()->for($this->workspace)->create(['name' => 'Stripe']),
        Opportunity::factory()->for($this->workspace)->create(['name' => 'Airbnb renewal']),
        Opportunity::factory()->for($this->workspace)->create(['name' => 'Stripe expansion']),
    ];

    foreach ($records as $record) {
        resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $record);
    }

    $page = linkingPage($email);

    foreach ($records as $record) {
        $page->assertSeeHtml('data-linked-record="'.$record->getMorphClass().':'.$record->getKey().'"');
    }
});

it('searches only the records of the bound workspace and stops at twenty', function (): void {
    $email = linkingEmail($this->owner);
    Company::factory()->count(22)->for($this->workspace)->sequence(fn ($sequence): array => ['name' => 'Airline '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();
    Company::factory()->for(Workspace::factory())->create(['name' => 'Airbnb Elsewhere']);
    Opportunity::factory()->for($this->workspace)->create(['name' => 'Airbnb renewal']);

    $page = linkingPage($email)
        ->mountAction('linkRecord', arguments: ['emailId' => $email->getKey()])
        ->setActionData(['target_type' => 'company']);
    $search = fn (string $term): array => array_column($page->instance()->callSchemaComponentMethod('mountedActionSchema0.target_id', 'getSearchResultsForJs', ['search' => $term]), 'label');

    expect($search('airb'))->toBe(['Airbnb'])
        ->and($search('Air'))->toHaveCount(20)
        ->and($search('renewal'))->toBe([]);

    $page->setActionData(['target_type' => 'opportunity']);

    expect($search('renewal'))->toBe(['Airbnb renewal']);
});

it('answers a forged record from another workspace with not found and writes nothing', function (): void {
    $email = linkingEmail($this->owner);
    $foreign = Company::factory()->for(Workspace::factory())->create(['name' => 'Elsewhere']);

    linkingPage($email)
        ->mountAction('linkRecord', arguments: ['emailId' => $email->getKey()])
        ->setActionData(['target_type' => 'company', 'target_id' => $foreign->getKey()])
        ->callMountedAction()
        ->assertHasActionErrors(['target_id']);

    expect(DB::table('emailables')->where('email_id', $email->getKey())->count())->toBe(0);
});

it('tells a teammate who reached no copy that the drawer changed nothing', function (): void {
    $email = linkingEmail($this->owner);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $this->actingAs($this->teammate);

    linkingPage($email)
        ->mountAction('unlinkRecord', arguments: ['emailId' => $email->getKey(), 'targetType' => 'company', 'targetId' => $this->company->getKey()])
        ->callMountedAction()
        ->assertNotified('Only people who hold this conversation, or who were given full access to it, can change its records.')
        ->assertNotDispatched('email:links-changed');

    expect(linkSource($email, $this->company))->toBe('auto');
});

it('unlinks a chip from the drawer and links it again from the undo', function (): void {
    $email = linkingEmail($this->owner);
    resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $this->company);
    $chip = 'data-linked-record="company:'.$this->company->getKey().'"';

    linkingPage($email)
        ->assertSeeHtml($chip)
        ->mountAction('unlinkRecord', arguments: ['emailId' => $email->getKey(), 'targetType' => 'company', 'targetId' => $this->company->getKey()])
        ->assertActionMounted('unlinkRecord')
        ->assertMountedActionModalSee('Remove this conversation from Airbnb?')
        ->callMountedAction()
        ->assertNotified('Removed from Airbnb')
        ->assertDispatched('email:links-changed')
        ->call('refreshEmailLinks')
        ->assertDontSeeHtml($chip);

    expect(linkSource($email, $this->company))->toBeNull()
        ->and(EmailLinkSuppression::query()->count())->toBe(1);

    livewire(EmailAccessNotificationHandler::class)
        ->dispatch('relink-email-record', emailId: $email->getKey(), targetType: 'company', targetId: $this->company->getKey())
        ->assertNotified('Linked to Airbnb');

    expect(linkSource($email, $this->company))->toBe('manual')
        ->and(EmailLinkSuppression::query()->count())->toBe(0);
});
