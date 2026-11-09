{{-- Transcript receipt for one decided proposal group (a plan, or a plan of
     one). Expects the Alpine scope var `group`.

     Two shapes, chosen by what happened. When any step was approved, the
     receipt is a head ("Applied 3 changes") over one check line per write, and
     a step that was left out or declined stays as a struck line beside them.
     When nothing was approved, the whole proposal is one grey settled line
     with the "Propose again" button on the latest one. Icons and words come
     from ProposalOutcome, so no colour carries meaning here. --}}
<div class="space-y-1" data-proposal-receipt>
    <template x-if="receiptSettled(group)">
        <div data-proposal-settled class="flex flex-wrap items-center gap-x-2 gap-y-1 px-2 text-sm text-gray-500 dark:text-gray-400">
            <span class="shrink-0" aria-hidden="true" x-html="outcomeIcon(settledOutcome(group))"></span>
            <span class="min-w-0 line-through decoration-gray-300 dark:decoration-gray-600" x-text="settledSummary(group)"></span>
            <span x-text="'· ' + outcomeLabel(settledOutcome(group))"></span>

            <template x-if="canProposeAgain(group)">
                <button
                    type="button"
                    data-propose-again
                    x-on:click="proposeAgain(group)"
                    x-text="proposalTexts.proposeAgain"
                    class="rounded text-xs font-medium text-primary-600 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-primary-400"
                ></button>
            </template>
        </div>
    </template>

    <template x-if="!receiptSettled(group)">
        <div>
            <template x-if="receiptApplied(group)">
                <p data-proposal-receipt-head class="px-2 text-xs text-gray-500 dark:text-gray-400" x-text="receiptHead(group)"></p>
            </template>

            <div class="mt-1 space-y-0.5">
                <template x-for="action in group.actions" :key="action.pending_action_id">
                    <div>
                        @include('chat::livewire.chat.partials._proposal-card-body')
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
