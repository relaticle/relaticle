<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\PeopleEmailsPage;
use App\Models\AiSummary;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Relaticle\EmailIntegration\Agents\ThreadSummarizer;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

function fakeSummary(string $text, int $promptTokens, int $completionTokens): TextResponse
{
    return new TextResponse(
        $text,
        new Usage($promptTokens, $completionTokens),
        new Meta(
            (string) config('services.email_summary.provider'),
            (string) config('services.email_summary.model'),
        ),
    );
}

mutates(EmailThreadSummaryService::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

function makeThreadWithEmail(): EmailThread
{
    $thread = EmailThread::factory()->create([
        'team_id' => test()->team->id,
        'connected_account_id' => test()->account->getKey(),
    ]);

    $email = Email::factory()->create([
        'team_id' => test()->team->id,
        'user_id' => test()->owner->id,
        'connected_account_id' => test()->account->getKey(),
        'thread_id' => $thread->thread_id,
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'prospect@customer.test',
    ]);
    EmailBody::factory()->create(['email_id' => $email->getKey()]);

    return $thread;
}

it('generates and caches a summary for an email thread', function (): void {
    ThreadSummarizer::fake([
        fakeSummary('The prospect requested pricing and the account manager will follow up next week.', 120, 60),
    ]);

    $thread = makeThreadWithEmail();

    $summary = resolve(EmailThreadSummaryService::class)->getSummary($thread, $this->owner);

    expect($summary)
        ->toBeInstanceOf(AiSummary::class)
        ->summary->toBe('The prospect requested pricing and the account manager will follow up next week.')
        ->model_used->toBe(config('services.email_summary.model'))
        ->prompt_tokens->toBe(120)
        ->completion_tokens->toBe(60);

    $this->assertDatabaseHas('ai_summaries', [
        'summarizable_type' => $thread->getMorphClass(),
        'summarizable_id' => $thread->getKey(),
        'team_id' => $this->team->getKey(),
    ]);
});

it('returns the cached summary without calling the model again', function (): void {
    $thread = makeThreadWithEmail();

    ThreadSummarizer::fake(['Cached thread summary']);
    $cached = resolve(EmailThreadSummaryService::class)->getSummary($thread, $this->owner);
    ThreadSummarizer::fake()->preventStrayPrompts();

    $summary = resolve(EmailThreadSummaryService::class)->getSummary($thread->fresh(), $this->owner);

    expect($summary->id)->toBe($cached->id)
        ->and($summary->summary)->toBe('Cached thread summary');

    $this->assertDatabaseCount('ai_summaries', 1);
});

it('regenerates the summary when requested', function (): void {
    $thread = makeThreadWithEmail();

    AiSummary::query()->create([
        'team_id' => $this->team->getKey(),
        'summarizable_type' => $thread->getMorphClass(),
        'summarizable_id' => $thread->getKey(),
        'summary' => 'Old summary',
        'model_used' => 'gpt-4o-mini',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

    ThreadSummarizer::fake([
        fakeSummary('Fresh summary', 100, 50),
    ]);

    $summary = resolve(EmailThreadSummaryService::class)
        ->getSummary($thread->fresh(), $this->owner, regenerate: true);

    expect($summary->summary)->toBe('Fresh summary');

    $this->assertDatabaseCount('ai_summaries', 1);
    $this->assertDatabaseHas('ai_summaries', ['summary' => 'Fresh summary']);
});

it('does not expose cached private content to a viewer of one shared message', function (bool $revokeShare): void {
    $thread = makeThreadWithEmail();
    $shared = $thread->emails()->firstOrFail();
    $shared->update(['privacy_tier' => EmailPrivacyTier::PRIVATE]);
    $private = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'thread_id' => $thread->thread_id,
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
    ]);
    EmailBody::factory()->create(['email_id' => $private->getKey(), 'body_text' => 'Confidential acquisition budget']);
    $person = People::factory()->create(['team_id' => $this->team->id, 'creator_id' => $this->owner->id]);
    $person->emails()->attach([$shared->getKey(), $private->getKey()]);
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($viewer, ['role' => 'editor']);
    EmailShare::factory()->create([
        'team_id' => $this->team->id,
        'email_id' => $shared->getKey(),
        'shared_by' => $this->owner->id,
        'shared_with' => $viewer->id,
        'tier' => EmailPrivacyTier::FULL->value,
    ]);
    if ($revokeShare) {
        EmailShare::factory()->create([
            'team_id' => $this->team->id,
            'email_id' => $private->getKey(),
            'shared_by' => $this->owner->id,
            'shared_with' => $viewer->id,
            'tier' => EmailPrivacyTier::FULL->value,
        ]);
        $this->actingAs($viewer->refresh());
    }

    ThreadSummarizer::fake(fn (string $prompt): string => str_contains($prompt, 'Confidential acquisition budget')
        ? 'Confidential acquisition budget summary'
        : 'Shared message summary');

    livewire(PeopleEmailsPage::class, ['record' => $person->getKey()])
        ->mountAction('summarizeThread', arguments: ['emailId' => $shared->getKey()])
        ->assertMountedActionModalSee('Confidential acquisition budget summary');

    $private->shares()->delete();
    $this->actingAs($viewer->refresh());
    expect($viewer->can('viewBody', $shared->fresh()))->toBeTrue();

    livewire(PeopleEmailsPage::class, ['record' => $person->getKey()])
        ->mountAction('summarizeThread', arguments: ['emailId' => $shared->getKey()])
        ->assertMountedActionModalSee('Shared message summary')
        ->assertMountedActionModalDontSee('Confidential acquisition budget summary');
})->with(['another viewer' => false, 'revoked share' => true]);

it('regenerates legacy summaries without a permission fingerprint', function (): void {
    $thread = makeThreadWithEmail();
    $email = $thread->emails()->firstOrFail();
    $person = People::factory()->create(['team_id' => $this->team->id, 'creator_id' => $this->owner->id]);
    $person->emails()->attach($email->getKey());
    AiSummary::query()->create([
        'team_id' => $this->team->id,
        'summarizable_type' => $thread->getMorphClass(),
        'summarizable_id' => $thread->getKey(),
        'summary' => 'Legacy unscoped summary',
        'model_used' => 'gpt-4o-mini',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);
    ThreadSummarizer::fake(['Verified summary']);

    livewire(PeopleEmailsPage::class, ['record' => $person->getKey()])
        ->mountAction('summarizeThread', arguments: ['emailId' => $email->getKey()])
        ->assertMountedActionModalSee('Verified summary')
        ->assertMountedActionModalDontSee('Legacy unscoped summary');
});
