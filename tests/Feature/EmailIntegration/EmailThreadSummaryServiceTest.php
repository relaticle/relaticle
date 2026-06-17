<?php

declare(strict_types=1);

use App\Models\AiSummary;
use App\Models\User;
use Filament\Facades\Filament;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

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

    EmailParticipant::factory()->from()->create(['email_id' => $email->getKey()]);
    EmailBody::factory()->create(['email_id' => $email->getKey()]);

    return $thread;
}

it('generates and caches a summary for an email thread', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('The prospect requested pricing and the account manager will follow up next week.')
            ->withUsage(new Usage(120, 60))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $thread = makeThreadWithEmail();

    $summary = resolve(EmailThreadSummaryService::class)->getSummary($thread, $this->owner);

    expect($summary)
        ->toBeInstanceOf(AiSummary::class)
        ->summary->toBe('The prospect requested pricing and the account manager will follow up next week.')
        ->model_used->toBe(config('services.openai.summary_model'))
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

    $cached = AiSummary::query()->create([
        'team_id' => $this->team->getKey(),
        'summarizable_type' => $thread->getMorphClass(),
        'summarizable_id' => $thread->getKey(),
        'summary' => 'Cached thread summary',
        'model_used' => 'gpt-4o-mini',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

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

    Prism::fake([
        TextResponseFake::make()
            ->withText('Fresh summary')
            ->withUsage(new Usage(100, 50))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $summary = resolve(EmailThreadSummaryService::class)
        ->getSummary($thread->fresh(), $this->owner, regenerate: true);

    expect($summary->summary)->toBe('Fresh summary');

    $this->assertDatabaseCount('ai_summaries', 1);
    $this->assertDatabaseHas('ai_summaries', ['summary' => 'Fresh summary']);
});
