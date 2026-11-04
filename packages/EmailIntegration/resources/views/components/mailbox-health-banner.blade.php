@props(['mailboxes'])

@if ($mailboxes->isNotEmpty())
    <div {{ $attributes->class(['flex flex-col gap-2 px-4 py-3 lg:px-6']) }}>
        @foreach ($mailboxes as $mailbox)
            @php
                $remedyUrl = $mailbox->health->remedyUrl($mailbox->workspace);
                $lastSyncedAgo = $mailbox->last_synced_at?->diffForHumans();
                $copyKey = $mailbox->health === \Relaticle\EmailIntegration\Enums\MailboxHealth::Stale && $lastSyncedAgo === null
                    ? 'stale_never'
                    : $mailbox->health->value;
            @endphp

            <div
                role="status"
                data-mailbox-health="{{ $mailbox->health->value }}"
                wire:key="mailbox-health-{{ $mailbox->getKey() }}"
                class="flex flex-col gap-3 rounded-lg bg-warning-50 px-4 py-3 text-warning-800 ring-1 ring-warning-600/20 sm:flex-row sm:items-center dark:bg-warning-500/10 dark:text-warning-200 dark:ring-warning-400/20"
            >
                <div class="flex min-w-0 flex-1 items-start gap-3">
                    <x-ri-error-warning-fill class="mt-0.5 size-5 shrink-0 text-warning-500 dark:text-warning-400" aria-hidden="true" />

                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ $mailbox->health->getLabel() }}</p>

                        <p class="text-sm break-words">
                            {{ __("filament/pages/email-accounts.health.banner.{$copyKey}", [
                                'mailbox' => $mailbox->email_address,
                                'ago' => $lastSyncedAgo,
                            ]) }}
                        </p>
                    </div>
                </div>

                @if ($remedyUrl !== null)
                    <x-filament::button
                        tag="a"
                        :href="$remedyUrl"
                        color="warning"
                        size="sm"
                        class="shrink-0 self-start sm:self-auto"
                    >
                        {{ $mailbox->health->needsReconnect()
                            ? __('filament/pages/email-accounts.actions.reconnect')
                            : __('filament/pages/email-accounts.health.banner.billing_action') }}
                    </x-filament::button>
                @endif
            </div>
        @endforeach
    </div>
@endif
