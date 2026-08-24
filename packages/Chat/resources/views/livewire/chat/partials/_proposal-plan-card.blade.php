{{-- Transcript audit card for a PLAN: the several proposals one assistant turn
     produced, decided together. Expects the Alpine scope var `group`.

     One surface, one header, and the steps stacked in the order they ran, each
     keeping the same body a lone proposal renders, so the audit trail reads as
     the single decision the user actually made. The header carries the outcome,
     because a decided plan is read for what happened to it, not for how many
     steps it had. --}}
<div class="overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]">
    <div class="flex items-center gap-2.5 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
            <x-heroicon-o-queue-list class="h-3.5 w-3.5" />
        </span>

        <p
            class="min-w-0 flex-1 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white"
            x-text="@js(__(':count steps')).replace(':count', String(group.actions.length))"
        ></p>

        <span
            class="shrink-0 text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500"
            x-text="(() => {
                const total = group.actions.length;
                if (group.actions.some((a) => a.status === 'pending')) return '';
                const approved = group.actions.filter((a) => a.status === 'approved').length;
                if (approved === total) return @js(__('All approved'));
                if (approved === 0) return @js(__('None approved'));
                return @js(__(':approved of :count approved'))
                    .replace(':approved', String(approved))
                    .replace(':count', String(total));
            })()"
        ></span>
    </div>

    <div class="divide-y divide-gray-100 dark:divide-white/5">
        <template x-for="(action, stepIdx) in group.actions" :key="action.pending_action_id">
            <div class="relative">
                {{-- The step's number and the connector between steps: the order the
                     writes ran in is the one fact a flat list of cards loses. The
                     rail is drawn over the step rather than beside it, and ignores
                     the pointer, so the row's own hover and click cover its gutter
                     instead of stopping at it. --}}
                <span
                    class="pointer-events-none absolute start-3.5 top-3 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-gray-100 text-[length:var(--text-pico)] font-semibold tabular-nums text-gray-500 dark:bg-white/10 dark:text-gray-400"
                    aria-hidden="true"
                    x-text="stepIdx + 1"
                ></span>

                <span
                    class="pointer-events-none absolute bottom-0 start-[1.45rem] top-8 z-10 w-px bg-gray-200 dark:bg-white/10"
                    :class="stepIdx === group.actions.length - 1 ? 'hidden' : ''"
                    aria-hidden="true"
                ></span>

                @include('chat::livewire.chat.partials._proposal-card-body', ['inPlan' => true])

                {{-- A step cancelled by a rejection above it explains itself, rather
                     than reading as an unexplained "Rejected". --}}
                <template x-if="action.cancelled_by">
                    <p class="ps-9 pe-4 pb-3 text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500">
                        {{ __('Cancelled with the step it depended on.') }}
                    </p>
                </template>
            </div>
        </template>
    </div>
</div>
