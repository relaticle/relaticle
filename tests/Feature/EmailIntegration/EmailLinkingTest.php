<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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
use Relaticle\EmailIntegration\Jobs\RecomputeRecordEmailMetricsJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLinkSuppression;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(
    LinkEmailToRecord::class,
    UnlinkEmailFromRecord::class,
    PrepareEmailRecordLink::class,
    RecordCommunicationMetrics::class,
    RecomputeRecordEmailMetricsJob::class,
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

it('links every copy of the conversation the user can see and pins it', function (): void {
    $own = linkingEmail($this->owner);
    $teammateCopy = linkingEmail($this->teammate);
    $teammateCopy->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $privateCopy = linkingEmail($this->teammate, [
        'rfc_message_id' => '<renewal-reply@airbnb.test>',
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'privacy_tier_customized' => true,
    ]);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $own, $this->company);

    expect(linkSource($own, $this->company))->toBe('manual')
        ->and(linkSource($teammateCopy, $this->company))->toBe('manual')
        ->and(linkSource($privateCopy, $this->company))->toBeNull();
});

it('unlinks every visible copy, so the conversation leaves the record for the user', function (): void {
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
        ->and(EmailLinkSuppression::query()->where('user_id', $this->owner->getKey())->pluck('email_id')->sort()->values()->all())
        ->toBe(collect([$own->getKey(), $teammateCopy->getKey()])->sort()->values()->all());
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
