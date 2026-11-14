@props(['senders', 'expanded' => null, 'rows' => [], 'currentKey' => null])

<div class="divide-y divide-gray-200/60 dark:divide-white/10" data-unlinked-senders>
    @forelse ($senders->items() as $sender)
        @php
            $isExpanded = $expanded === $sender->address;
            $arguments = ['address' => $sender->address, 'name' => $sender->name];
        @endphp

        <div data-unlinked-sender="{{ $sender->address }}" wire:key="unlinked-sender-{{ $sender->address }}">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:flex-nowrap sm:px-6">
                <button
                    type="button"
                    wire:click="toggleSender({{ Js::from($sender->address) }})"
                    aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                    aria-label="{{ __('filament/pages/email-inbox.unlinked_senders.expand', ['name' => $sender->displayName()]) }}"
                    data-unlinked-sender-toggle
                    class="flex min-w-0 flex-1 items-start gap-2 rounded-md text-start outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                >
                    <x-filament::icon
                        :icon="$isExpanded ? 'ri-arrow-down-s-line' : 'ri-arrow-right-s-line'"
                        class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-950 dark:text-white">{{ $sender->displayName() }}</span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $sender->address }}</span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end gap-0.5 text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ trans_choice('filament/pages/email-inbox.unlinked_senders.conversations', $sender->conversationCount, ['count' => $sender->conversationCount]) }}</span>
                        @if ($sender->sentToCount > 0)
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ trans_choice('filament/pages/email-inbox.unlinked_senders.wrote', $sender->sentToCount, ['count' => $sender->sentToCount]) }}</span>
                        @endif
                    </span>
                </button>

                <div class="flex w-full shrink-0 items-center gap-2 ps-6 sm:w-auto sm:ps-0">
                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="ri-user-add-line"
                        data-sender-action="create-person"
                        wire:click="mountAction('createSenderPerson', {{ Js::from($arguments) }})"
                    >
                        {{ __('filament/pages/email-inbox.unlinked_senders.create_person.label') }}
                    </x-filament::button>
                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="ri-link"
                        data-sender-action="link-existing"
                        wire:click="mountAction('linkSenderToPerson', {{ Js::from($arguments) }})"
                    >
                        {{ __('filament/pages/email-inbox.unlinked_senders.link_existing.label') }}
                    </x-filament::button>
                </div>
            </div>

            @if ($isExpanded)
                <div class="divide-y divide-gray-200/60 border-t border-gray-200/60 bg-gray-50/50 dark:divide-white/10 dark:border-white/10 dark:bg-white/[0.02]" data-unlinked-sender-conversations>
                    @foreach ($rows as $row)
                        <div class="flex items-center" wire:key="unlinked-row-{{ $row->conversationKey }}">
                            <x-email-integration::conversation-row :row="$row" selectable :current="$row->conversationKey === $currentKey" class="min-w-0 flex-1" />
                            <button
                                type="button"
                                data-conversation-link="{{ $row->conversationKey }}"
                                wire:click="mountAction('linkRecord', {{ Js::from(['emailId' => $row->viewerCopyEmailId]) }})"
                                class="me-4 inline-flex shrink-0 items-center gap-0.5 rounded-md px-1.5 py-1 text-xs font-medium text-gray-500 ring-1 ring-inset ring-gray-200 outline-none hover:bg-gray-100 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-primary-500 sm:me-6 dark:text-gray-400 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-gray-200"
                            >
                                <x-filament::icon icon="ri-link" class="size-3.5" aria-hidden="true" />
                                {{ __('filament/pages/email-inbox.unlinked_senders.link') }}
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <x-filament::empty-state
            :heading="__('filament/pages/email-inbox.views.empty.unlinked')"
            icon="heroicon-o-envelope"
            icon-color="gray"
            :contained="false"
        />
    @endforelse
</div>

@if ($senders->previousCursor() !== null || $senders->nextCursor() !== null)
    <div class="flex items-center justify-between border-t border-gray-200/60 px-4 py-3 sm:px-6 dark:border-white/10">
        @foreach (['newer' => ['previousSendersPage', $senders->previousCursor(), 'heroicon-o-chevron-left'], 'older' => ['nextSendersPage', $senders->nextCursor(), 'heroicon-o-chevron-right']] as $label => [$method, $cursor, $icon])
            <button
                type="button"
                wire:click="{{ $method }}"
                wire:loading.attr="disabled"
                @disabled($cursor === null)
                data-senders-page="{{ $label }}"
                class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
            >
                @if ($label === 'newer')
                    <x-filament::icon :icon="$icon" class="h-3.5 w-3.5" />
                @endif
                {{ __("filament/pages/email-inbox.unlinked_senders.{$label}") }}
                @if ($label === 'older')
                    <x-filament::icon :icon="$icon" class="h-3.5 w-3.5" />
                @endif
            </button>
        @endforeach
    </div>
@endif
