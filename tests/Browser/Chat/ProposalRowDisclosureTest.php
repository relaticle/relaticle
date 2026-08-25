<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

/**
 * A decided proposal in the transcript is an audit line whose whole row is the
 * disclosure: clicking anywhere on it opens the fields. The record keeps its own
 * small link at the end of the title, so reaching the record stays one click but
 * is never what an imprecise click on the row does.
 */
function seedDecidedProposal(User $user, int|string $teamId, string $conversationId, People $person): void
{
    $pending = PendingAction::query()->create([
        'team_id' => $teamId,
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'action_class' => 'App\\Actions\\People\\CreatePeople',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['name' => $person->name],
        'display_data' => [
            'title' => 'Create Person',
            'summary' => "Create person \"{$person->name}\"",
            'fields' => [['label' => 'Name', 'value' => $person->name]],
        ],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
        'result_data' => ['id' => (string) $person->id, 'type' => 'people'],
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant',
        'content' => 'Done.',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => json_encode([[
            'id' => 'toolu_'.Str::random(8),
            'name' => 'CreatePersonTool',
            'result' => json_encode([
                'type' => 'pending_action',
                'pending_action_id' => $pending->id,
                'action' => 'CreatePeople',
                'entity_type' => 'people',
                'operation' => 'create',
                'data' => ['name' => $person->name],
                'display' => $pending->display_data,
            ]),
        ]]),
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('opens the fields from anywhere on the row and reaches the record only from the title link', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $person = People::factory()->for($team)->create(['name' => 'Sam']);

    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'decided proposal', $conversationId);
    seedDecidedProposal($user, $team->getKey(), $conversationId, $person);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSee('Sam')
        ->assertSee('Create')
        ->assertMissing('[data-proposal-details]')
        ->assertVisible('[data-proposal-record-chip]')
        ->assertVisible('[data-proposal-record-link]');

    // The toggle covers the row, and the record link is the only interactive
    // element the pointer can reach on top of it.
    $hitTest = $page->script(<<<'JS'
        (() => {
            const row = document.querySelector('[data-proposal-row]');
            const link = document.querySelector('[data-proposal-record-link]');
            const chip = document.querySelector('[data-proposal-record-chip]');
            const rowBox = row.getBoundingClientRect();
            const linkBox = link.getBoundingClientRect();

            const at = (x, y) => {
                const el = document.elementFromPoint(x, y);
                return el && el.closest('[data-proposal-record-link]') ? 'link'
                    : el && el.closest('[data-proposal-row]') ? 'row'
                    : 'other';
            };

            return {
                rowTag: row.tagName,
                linkTag: link.tagName,
                chipText: chip.textContent.trim(),
                chipType: chip.dataset.recordType,
                expanded: row.getAttribute('aria-expanded'),
                anchorsInsideToggle: row.querySelectorAll('a').length,
                atSummary: at(rowBox.left + 40, rowBox.top + rowBox.height / 2),
                atStatusPill: at(rowBox.right - 90, rowBox.top + rowBox.height / 2),
                atLink: at(linkBox.left + linkBox.width / 2, linkBox.top + linkBox.height / 2),
                rowWidth: Math.round(rowBox.width),
                linkWidth: Math.round(linkBox.width),
            };
        })();
    JS);

    expect($hitTest['rowTag'])->toBe('BUTTON')
        ->and($hitTest['linkTag'])->toBe('A')
        ->and($hitTest['chipText'])->toBe('Sam')
        ->and($hitTest['chipType'])->toBe('people')
        ->and($hitTest['expanded'])->toBe('false')
        ->and($hitTest['anchorsInsideToggle'])->toBe(0)
        ->and($hitTest['atSummary'])->toBe('row')
        ->and($hitTest['atStatusPill'])->toBe('row')
        ->and($hitTest['atLink'])->toBe('link')
        ->and($hitTest['rowWidth'])->toBeGreaterThan($hitTest['linkWidth'] * 5);

    // Clicking the row opens the fields and stays in the conversation.
    $page->click('[data-proposal-row]')
        ->assertVisible('[data-proposal-details]')
        ->assertAriaAttribute('[data-proposal-row]', 'expanded', 'true')
        ->assertSeeIn('[data-proposal-details]', 'Sam')
        ->assertPathIs("/app/{$team->slug}/chats/{$conversationId}");

    $page->click('[data-proposal-row]')
        ->assertMissing('[data-proposal-details]');

    // The link, and only the link, leaves for the record.
    $page->click('[data-proposal-record-link]')
        ->assertPathIs("/app/{$team->slug}/people/{$person->id}");
});
