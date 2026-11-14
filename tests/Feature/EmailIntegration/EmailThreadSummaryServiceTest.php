<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Actions\SummarizeEmailConversation;
use Relaticle\Chat\Jobs\SummarizeEmailConversationJob;
use Relaticle\Chat\Livewire\Chat\EmailRelaSlot;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Agents\ThreadSummarizer;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\AiSummary;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailLabel;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

function fakeSummary(string $text, int $promptTokens, int $completionTokens): TextResponse
{
    return new TextResponse(
        $text,
        new TextUsage($promptTokens, $completionTokens),
        new Meta(
            'openai',
            'gpt-4.1-mini',
        ),
    );
}

mutates(EmailThreadSummaryService::class);

beforeEach(function (): void {
    Feature::define(Billing::class, true);
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $this->workspace->id], ['credits_remaining' => 100, 'credits_used' => 0]);
});

function summaryConversation(EmailThread $thread): EmailConversation
{
    $key = hash('sha256', $thread->id);
    $thread->emails()->update(['conversation_key' => $key]);
    resolve(RefreshConversationSummary::class)->execute($thread->workspace_id, $key, broadcast: false);

    return EmailConversation::query()->where('workspace_id', $thread->workspace_id)->where('conversation_key', $key)->firstOrFail();
}

function summarizeTestThread(EmailThread $thread, User $viewer, bool $regenerate = false): ?AiSummary
{
    $conversation = summaryConversation($thread);

    return resolve(SummarizeEmailConversation::class)->execute($viewer, $thread->workspace_id, $conversation->conversation_key, $regenerate);
}

function makeThreadWithEmail(): EmailThread
{
    $thread = EmailThread::factory()->create([
        'workspace_id' => test()->workspace->id,
        'connected_account_id' => test()->account->getKey(),
    ]);

    $email = Email::factory()->create([
        'workspace_id' => test()->workspace->id,
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

    $summary = summarizeTestThread($thread, $this->owner);

    expect($summary)
        ->toBeInstanceOf(AiSummary::class)
        ->summary->toBe('The prospect requested pricing and the account manager will follow up next week.')
        ->model_used->not->toBeEmpty()
        ->prompt_tokens->toBe(120)
        ->completion_tokens->toBe(60);

    $this->assertDatabaseHas('ai_summaries', [
        'summarizable_type' => summaryConversation($thread)->getMorphClass(),
        'summarizable_id' => summaryConversation($thread)->getKey(),
        'workspace_id' => $this->workspace->getKey(),
    ]);
});

it('returns the cached summary without calling the model again', function (): void {
    $thread = makeThreadWithEmail();

    ThreadSummarizer::fake(['Cached thread summary']);
    $cached = summarizeTestThread($thread, $this->owner);
    ThreadSummarizer::fake()->preventStrayPrompts();

    $summary = summarizeTestThread($thread->fresh(), $this->owner);

    expect($summary->id)->toBe($cached->id)
        ->and($summary->summary)->toBe('Cached thread summary');

    $this->assertDatabaseCount('ai_summaries', 1);
});

it('generates a metered summary in a job without an authenticated panel request', function (): void {
    $conversation = summaryConversation(makeThreadWithEmail());
    ThreadSummarizer::fake([fakeSummary('Pricing for forty seats.', 120, 60)]);
    Filament::setTenant(null);
    auth()->logout();

    $job = new SummarizeEmailConversationJob($this->owner->id, $this->workspace->id, $conversation->conversation_key);
    $job->handle(resolve(SummarizeEmailConversation::class));

    expect(AiSummary::query()->sole()->user_id)->toBe($this->owner->id)
        ->and(AiCreditTransaction::query()->where('type', 'summary')->count())->toBe(1)
        ->and(AiCreditBalance::query()->sole()->credits_remaining)->toBeLessThan(100);
});

it('stores separate viewer summaries and charges nothing on a cached reopen', function (): void {
    $thread = makeThreadWithEmail();
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($viewer, ['role' => 'member']);
    $thread->emails()->firstOrFail()->people()->attach(People::factory()->create(['workspace_id' => $this->workspace->id])->id);
    ThreadSummarizer::fake(['Owner summary', 'Viewer summary']);

    $ownerSummary = summarizeTestThread($thread, $this->owner);
    expect(summarizeTestThread($thread, $viewer)->summary)->toBe('Viewer summary');
    $balance = AiCreditBalance::query()->sole()->credits_remaining;
    ThreadSummarizer::fake()->preventStrayPrompts();
    expect(summarizeTestThread($thread, $this->owner)->id)->toBe($ownerSummary->id)
        ->and(AiSummary::query()->count())->toBe(2)
        ->and(AiCreditBalance::query()->sole()->credits_remaining)->toBe($balance);
});

it('keeps facts available without a model and stores nothing with no credits', function (): void {
    $conversation = summaryConversation(makeThreadWithEmail());
    AiCreditBalance::query()->update(['credits_remaining' => 0, 'purchased_credits' => 0]);
    ThreadSummarizer::fake()->preventStrayPrompts();
    expect(resolve(SummarizeEmailConversation::class)->execute($this->owner, $this->workspace->id, $conversation->conversation_key))->toBeNull();
    $this->assertDatabaseCount('ai_summaries', 0);
    foreach (['anthropic', 'openai', 'gemini', 'groq', 'xai', 'deepseek', 'mistral', 'openrouter'] as $provider) {
        config(["ai.providers.{$provider}.key" => null]);
    }
    config(['ai.providers.ollama.url' => null]);

    livewire(EmailRelaSlot::class, ['conversationKey' => $conversation->conversation_key])
        ->assertSee(__('filament/emails/composer.rela.last_from_them'))
        ->assertDontSee(__('filament/emails/composer.rela.suggest'));
});

it('regenerates the summary when requested', function (): void {
    $thread = makeThreadWithEmail();
    $conversation = summaryConversation($thread);

    AiSummary::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'summarizable_type' => $conversation->getMorphClass(),
        'summarizable_id' => $conversation->getKey(),
        'user_id' => $this->owner->id,
        'summary' => 'Old summary',
        'model_used' => 'gpt-4o-mini',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);

    ThreadSummarizer::fake([
        fakeSummary('Fresh summary', 100, 50),
    ]);

    $summary = summarizeTestThread($thread->fresh(), $this->owner, regenerate: true);

    expect($summary->summary)->toBe('Fresh summary');

    $this->assertDatabaseCount('ai_summaries', 1);
    $this->assertDatabaseHas('ai_summaries', ['summary' => 'Fresh summary']);
});

it('does not expose cached private content to a viewer of one shared message', function (bool $revokeShare): void {
    $thread = makeThreadWithEmail();
    $shared = $thread->emails()->firstOrFail();
    $shared->update(['privacy_tier' => EmailPrivacyTier::PRIVATE]);
    $private = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'thread_id' => $thread->thread_id,
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
    ]);
    EmailBody::factory()->create(['email_id' => $private->getKey(), 'body_text' => 'Confidential acquisition budget']);
    $person = People::factory()->create(['workspace_id' => $this->workspace->id, 'creator_id' => $this->owner->id]);
    $person->emails()->attach([$shared->getKey(), $private->getKey()]);
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($viewer, ['role' => 'member']);
    EmailShare::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $shared->getKey(),
        'shared_by' => $this->owner->id,
        'shared_with' => $viewer->id,
        'tier' => EmailPrivacyTier::FULL->value,
    ]);
    if ($revokeShare) {
        EmailShare::factory()->create([
            'workspace_id' => $this->workspace->id,
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

    $firstViewer = $revokeShare ? $viewer : $this->owner;
    expect(summarizeTestThread($thread, $firstViewer)->summary)->toBe('Confidential acquisition budget summary');

    $private->shares()->delete();
    $this->actingAs($viewer->refresh());
    expect($viewer->can('viewBody', $shared->fresh()))->toBeTrue();

    expect(summarizeTestThread($thread, $viewer)->summary)->toBe('Shared message summary');
})->with(['another viewer' => false, 'revoked share' => true]);

it('omits hidden message metadata from the summary prompt', function (): void {
    $hiddenSentAt = now()->setDate(2020, 3, 11)->setTime(8, 17, 0);
    $thread = EmailThread::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
        'subject' => 'Confidential acquisition talks',
        'email_count' => 2,
        'participant_count' => 7,
        'first_email_at' => $hiddenSentAt,
        'last_email_at' => now(),
    ]);
    $hidden = Email::factory()->private()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'thread_id' => $thread->thread_id,
        'subject' => 'Confidential acquisition talks',
        'sent_at' => $hiddenSentAt,
    ]);
    EmailParticipant::factory()->from()->create([
        'email_id' => $hidden->getKey(),
        'name' => 'Avery Counsel',
        'email_address' => 'avery.counsel@hidden.test',
    ]);
    EmailLabel::factory()->category(EmailCategory::Personal->value)->create([
        'email_id' => $hidden->getKey(),
    ]);
    EmailBody::factory()->create([
        'email_id' => $hidden->getKey(),
        'body_text' => 'Do not disclose the acquisition budget',
    ]);
    $shared = Email::factory()->private()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'thread_id' => $thread->thread_id,
        'subject' => 'Q3 pricing follow-up',
        'sent_at' => now(),
    ]);
    EmailParticipant::factory()->from()->create([
        'email_id' => $shared->getKey(),
        'name' => 'Maya Outreach',
        'email_address' => 'maya@acme.test',
    ]);
    EmailLabel::factory()->category(EmailCategory::Sales->value)->create([
        'email_id' => $shared->getKey(),
    ]);
    EmailBody::factory()->create([
        'email_id' => $shared->getKey(),
        'body_text' => 'Let us schedule a pricing call next week',
    ]);
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($viewer, ['role' => 'member']);
    EmailShare::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $shared->getKey(),
        'shared_by' => $this->owner->id,
        'shared_with' => $viewer->id,
        'tier' => EmailPrivacyTier::FULL->value,
    ]);
    $prompt = null;
    ThreadSummarizer::fake(function (string $input) use (&$prompt): string {
        $prompt = $input;

        return 'Shared message summary';
    });

    summarizeTestThread($thread, $viewer);

    expect($prompt)
        ->toContain('Q3 pricing follow-up')
        ->toContain('Let us schedule a pricing call next week')
        ->toContain('Maya Outreach')
        ->toContain(EmailCategory::Sales->value)
        ->not->toContain('Confidential acquisition talks')
        ->not->toContain('Avery Counsel')
        ->not->toContain('2020-03-11')
        ->not->toContain(EmailCategory::Personal->value)
        ->not->toContain('(restricted)');
});

it('regenerates legacy summaries without a permission fingerprint', function (): void {
    $thread = makeThreadWithEmail();
    $email = $thread->emails()->firstOrFail();
    $person = People::factory()->create(['workspace_id' => $this->workspace->id, 'creator_id' => $this->owner->id]);
    $person->emails()->attach($email->getKey());
    AiSummary::query()->create([
        'workspace_id' => $this->workspace->id,
        'summarizable_type' => $thread->getMorphClass(),
        'summarizable_id' => $thread->getKey(),
        'summary' => 'Legacy unscoped summary',
        'model_used' => 'gpt-4o-mini',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
    ]);
    ThreadSummarizer::fake(['Verified summary']);

    expect(summarizeTestThread($thread, $this->owner)->summary)->toBe('Verified summary');
});

it('shows a failure notice instead of erroring when the AI provider rejects the summary request', function (int $status, string $reported): void {
    config(['ai.providers.openai.key' => 'test-key']);
    $this->workspace->forceFill(['plan' => Plan::Pro])->save();
    $this->owner->forceFill(['ai_preferences' => ['default_model' => 'gpt-5.5']])->save();
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Provider rejected the request.']], $status)]);
    Exceptions::fake();
    $thread = makeThreadWithEmail();
    $email = $thread->emails()->firstOrFail();
    $person = People::factory()->create(['workspace_id' => $this->workspace->id, 'creator_id' => $this->owner->id]);
    $person->emails()->attach($email->getKey());

    $conversation = summaryConversation($thread);
    $job = new SummarizeEmailConversationJob($this->owner->id, $this->workspace->id, $conversation->conversation_key);
    $job->handle(resolve(SummarizeEmailConversation::class));
    livewire(EmailRelaSlot::class, ['conversationKey' => $conversation->conversation_key])
        ->set('summaryState', 'working')->call('check')
        ->assertSee(__('filament/emails/composer.rela.summary_failed'));

    Exceptions::assertReported($reported);
    expect($thread->aiSummary()->exists())->toBeFalse();
})->with([
    'no credit or rate limited' => [429, RateLimitedException::class],
    'invalid api key' => [401, RequestException::class],
    'provider unavailable' => [503, ProviderOverloadedException::class],
]);
