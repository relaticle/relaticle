<?php

declare(strict_types=1);

use App\Contracts\Entitlements;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Relaticle\Chat\Agents\ConversationTitler;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Agents\NextStepSuggester;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Services\CreditService;

mutates(CrmAssistant::class, ProcessChatMessage::class);

function languageTurnJob(User $user, string $conversationId): ProcessChatMessage
{
    return new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'Hallo',
        conversationId: $conversationId,
        resolved: ['provider' => 'ollama', 'model' => 'qwen3:8b', 'id' => 'ollama', 'source' => 'auto'],
        mentions: [],
        document: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hallo']]]]],
        turnId: (string) Str::ulid(),
    );
}

it('names the user language in the dynamic block, never the static one', function (): void {
    $german = (new CrmAssistant)->withLanguage('de')->withTurnOrigin(MessageOrigin::Resume);
    $english = (new CrmAssistant)->withLanguage('en')->withTurnOrigin(MessageOrigin::Resume);

    expect($german->dynamicInstructions())
        ->toContain('## Chosen language')
        ->toContain('German')
        ->and($english->dynamicInstructions())->toContain('English')
        ->and($german->staticInstructions())->toBe($english->staticInstructions())
        ->and($german->staticInstructions())->not->toContain('German')
        ->and($german->staticInstructions())->toContain("or the user's chosen language when they have typed nothing yet")
        ->and((new CrmAssistant)->dynamicInstructions())->not->toContain('## Chosen language');
});

it('leaves the chosen language out of a typed turn', function (): void {
    expect((new CrmAssistant)->withLanguage('de')->dynamicInstructions())
        ->not->toContain('## Chosen language')
        ->not->toContain('German');
});

/** @param array{id: string, name: string, kind: 'text'|'rows', row_count: int}|null $attachment */
function promptedInstructionsFor(User $user, MessageOrigin $origin, string $message = 'Hallo', ?array $attachment = null): string
{
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'Language',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CrmAssistant::fake(['ok']);

    new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: $message,
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        origin: $origin,
        attachment: $attachment,
    )->handle(resolve(CreditService::class));

    $instructions = '';

    CrmAssistant::assertPrompted(function ($prompt) use (&$instructions): bool {
        $instructions = (string) $prompt->agent->instructions();

        return true;
    });

    return $instructions;
}

it('hands the provider the chosen language on a system-opened turn', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);

    expect(promptedInstructionsFor($user, MessageOrigin::DataArrived))
        ->toContain("## Chosen language\nThe user's chosen language is German.");
});

it('hands the provider the chosen language on a typed turn that carries only an attachment', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);

    expect(promptedInstructionsFor($user, MessageOrigin::Typed, '', ['id' => (string) Str::uuid7(), 'name' => 'leads.csv', 'kind' => 'rows', 'row_count' => 3]))
        ->toContain("## Chosen language\nThe user's chosen language is German.");
});

it('hands the provider no chosen language on a typed turn', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);

    expect(promptedInstructionsFor($user, MessageOrigin::Typed))->not->toContain('## Chosen language');
});

it('runs the chat job in the user language and restores the worker locale even when it throws', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);
    $seen = [];

    $entitlements = Mockery::mock(Entitlements::class);
    $entitlements->shouldReceive('allowsAccess')->andReturnUsing(function () use (&$seen): never {
        $seen[] = app()->getLocale();

        throw new RuntimeException('boom');
    });
    app()->instance(Entitlements::class, $entitlements);

    app()->setLocale('fr');

    expect(fn () => languageTurnJob($user, (string) Str::uuid7())->handle(resolve(CreditService::class)))
        ->toThrow(RuntimeException::class, 'boom')
        ->and($seen)->toBe(['de'])
        ->and(app()->getLocale())->toBe('fr');
});

it('runs failed() in the user language and restores the worker locale', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'es']);
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'Language',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $seen = [];
    Event::listen(ChatStreamFailed::class, function () use (&$seen): void {
        $seen[] = app()->getLocale();
    });

    app()->setLocale('fr');
    languageTurnJob($user, $conversationId)->failed(new RuntimeException('boom'));

    expect($seen)->toBe(['es'])
        ->and(app()->getLocale())->toBe('fr');
});

it('gives the titler and suggester the user language as the fallback', function (): void {
    $titler = Locales::within('de', fn (): string => (new ConversationTitler)->instructions());
    $suggester = Locales::within('de', fn (): string => (new NextStepSuggester)->instructions());

    expect($titler)->toContain('write in the language of the <reply>, or in German when there is no <reply>')
        ->and($suggester)->toContain('write in the language of the <reply>, or in German when the <reply> has no text of its own')
        ->and((new ConversationTitler)->instructions())->toContain('or in English when there is no <reply>');
});
