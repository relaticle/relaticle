<?php

declare(strict_types=1);

use App\Models\User;

/**
 * The agent outcome summary (`proposalOutcome`) is pure Alpine view logic on the
 * chat-interface, derived from the persisted action shape that ListConversationMessages
 * emits (status, record, itemResults, display) — so it renders identically live and
 * after a reload. This drives the real method in the real page with synthetic finalized
 * actions, asserting the summary sentence for each operation/branch.
 */
it('summarizes a finalized proposal for each operation and branch', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $outcomes = json_decode((string) $page->script(<<<'JS'
        (() => {
            const host = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'))
                .find((el) => el.offsetParent !== null);
            const data = Alpine.$data(host);

            const singleCreate = {
                operation: 'create', status: 'approved',
                record: { label: 'Acme Corp' },
                display: { summary: 'Create company "Acme Corp"' },
            };
            const singleDelete = {
                operation: 'delete', status: 'approved',
                record: { label: 'Acme Corp' },
                display: { summary: 'Delete company "Acme Corp"' },
            };
            const discardedCreate = {
                operation: 'create', status: 'rejected',
                display: { summary: 'Create company "Brightwave"' },
            };
            const batchMixed = {
                operation: 'create', status: 'approved',
                display: { items: [
                    { summary: 'Create company "Nexora"', fields: [{ label: 'Name', value: 'Nexora' }] },
                    { summary: 'Create company "Crestline"', fields: [{ label: 'Name', value: 'Crestline' }] },
                    { summary: 'Create company "Summit"', fields: [{ label: 'Name', value: 'Summit' }] },
                ] },
                itemResults: {
                    0: { status: 'approved', record: { label: 'Nexora' } },
                    1: { status: 'skipped', record: null },
                    2: { status: 'approved', record: { label: 'Summit' } },
                },
            };
            const pending = { operation: 'create', status: 'pending' };

            return JSON.stringify({
                singleCreate: data.proposalOutcome(singleCreate),
                singleDelete: data.proposalOutcome(singleDelete),
                discardedCreate: data.proposalOutcome(discardedCreate),
                batchMixed: data.proposalOutcome(batchMixed),
                pending: data.proposalOutcome(pending),
            });
        })();
    JS), true);

    expect($outcomes['singleCreate'])->toBe('Created Acme Corp.')
        ->and($outcomes['singleDelete'])->toBe('Deleted Acme Corp.')
        ->and($outcomes['discardedCreate'])->toBe('Discarded Brightwave.')
        ->and($outcomes['batchMixed'])->toBe('Created Nexora and Summit; skipped Crestline.')
        ->and($outcomes['pending'])->toBeNull();
});
