<?php

declare(strict_types=1);

use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\StoredSteps;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

it('stores the listed tasks and the question after them as the replay reads them back', function (): void {
    $w = EvalWorkspace::seed();
    $conversationId = Prior::listedTasksThenQuestioned($w);

    $rows = AgentConversationMessage::query()->where('conversation_id', $conversationId)->orderBy('created_at')->orderBy('id')->get();
    $listed = $rows->get(1);
    $toolResults = StoredSteps::toolResults($listed->steps);
    $envelope = RelaTurn::envelopeFromRow($listed);
    $payload = json_decode($toolResults[0]['result'], true);

    expect($rows->pluck('role')->all())->toBe(['user', 'assistant', 'user', 'assistant'])
        ->and($rows->last()->content)->toEndWith('Which of those should I move?')
        ->and(json_decode($rows->last()->steps, true))->toBe([])
        ->and(json_decode($listed->steps, true))->toHaveCount(1)
        ->and($toolResults)->toHaveCount(1)
        ->and($toolResults[0]['name'])->toBe('ListTasksTool')
        ->and($payload['data'])->toHaveCount(19)
        ->and($envelope->toolCalls[0]['name'])->toBe('ListTasksTool')
        ->and($envelope->renderedRowIds[0])->toHaveCount(19)
        ->and($envelope->replayedBytes)->toBeGreaterThan(1000);

    $storedCalls = collect(json_decode($listed->steps, true))->flatMap(fn (array $step): array => $step['tool_calls']);

    expect($storedCalls)->not->toBeEmpty()
        ->and($storedCalls->pluck('result_id')->every(fn (?string $id): bool => is_string($id) && str_starts_with($id, 'call_')))->toBeTrue();
});

it('seeds each decided proposal on its own conversation with the matching status', function (): void {
    $w = EvalWorkspace::seed();

    $approved = Prior::approvedCompany($w);
    $rejected = Prior::rejectedTask($w);
    $superseded = Prior::supersededNote($w);

    $status = fn (array $prior): PendingActionStatus => PendingAction::query()
        ->where('conversation_id', $prior['conversationId'])
        ->where('turn_id', $prior['turnId'])
        ->sole()
        ->status;

    expect($status($approved))->toBe(PendingActionStatus::Approved)
        ->and($status($rejected))->toBe(PendingActionStatus::Rejected)
        ->and($status($superseded))->toBe(PendingActionStatus::Superseded)
        ->and(PendingAction::query()->where('conversation_id', $approved['conversationId'])->sole()->result_data['ids'])->toHaveCount(1)
        ->and(AgentConversationMessage::query()->where('conversation_id', $approved['conversationId'])->count())->toBe(2);
});

it('reads the approved company back as a just-decided record', function (): void {
    $w = EvalWorkspace::seed();
    $approved = Prior::approvedCompany($w);

    $resolved = resolve(PendingActionService::class)->resolvedForConversation($approved['conversationId'], $approved['turnId']);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['status'])->toBe('approved')
        ->and($resolved[0]['just_decided'])->toBeTrue()
        ->and($resolved[0]['records'][0]['label'])->toBe('Acme Robotics');
});
