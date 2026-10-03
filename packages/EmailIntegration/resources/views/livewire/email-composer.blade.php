<div
    @if ($dock === 'inline')
        {{-- The reader closes by removing this component along with itself, which is
             too late for an event-based "save your draft first". The reader finds the
             dock by this hook and awaits its save before unmounting. --}}
        data-inline-composer
    @endif
    {{-- Inline: the draft is appended to the reading pane's scroll region, so it is
         simply as tall as its message; the region does the scrolling. --}}
    @class(['shrink-0' => $dock === 'inline'])
    @if ($dock === 'floating')
        {{-- Opening waits on a round-trip, so the window frame shows the moment any
             trigger fires composer:open and stays until this component answers. --}}
        x-data="{
            opening: false,
            init() {
                $wire.$interceptMessage(({ onFinish }) => onFinish(() => this.opening = false))
            },
        }"
        x-on:composer:open.window="opening = ! $wire.isOpen"
        x-on:keydown.window="(() => {
            if ($event.key === 'c'
                && ! $event.metaKey && ! $event.ctrlKey && ! $event.altKey
                && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName)
                && ! $event.target.isContentEditable
            ) { $event.preventDefault(); $wire.dispatch('composer:open') }
        })()"
    @endif
>
    @php
        // Composing and editing a saved draft both open fitted to the screen, so a
        // draft looks the same wherever it came from, and the same as the reader it
        // sits alongside. Shrinking drops back to the corner window.
        $isModal = $dock === 'floating' && $isExpanded && ! $isMinimized;
        $isFloating = $dock === 'floating';
        $isFloatingExpanded = $isFloating && $isExpanded && ! $isMinimized;
        $isMassSendLayout = $isFloatingExpanded && $isMassSend;
        $showMassSendToggle = $isFloatingExpanded;
        $gutter = $isFloating ? 'px-4' : 'px-4 sm:px-6';
        $chromeButton = 'rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-200/70 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200';
        $minimizeLabel = $isMinimized ? __('filament/emails/composer.actions.restore') : __('filament/emails/composer.actions.minimize');
        $expandLabel = $isExpanded ? __('filament/emails/composer.actions.shrink') : __('filament/emails/composer.actions.expand');
        $closeLabel = __('filament/emails/composer.actions.close');
    @endphp

    @if ($dock === 'floating' && ! $isOpen)
        <div x-cloak x-show="opening" role="status" aria-label="{{ __('filament/emails/composer.opening') }}">
            <div class="ei-composer-opening-backdrop fixed inset-0 z-40 bg-gray-950/50"></div>

            <div class="ei-composer-opening-panel fixed left-1/2 top-1/2 z-50 flex h-[85vh] w-[calc(100%-2rem)] max-w-5xl -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-xl bg-white shadow-2xl shadow-gray-950/20 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:shadow-black/50 dark:ring-white/10">
                <div class="flex h-12 shrink-0 items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 pl-4 pr-4 dark:border-white/10 dark:bg-white/5">
                    <span class="flex items-center gap-2">
                        <x-heroicon-m-envelope class="h-4 w-4 shrink-0 text-primary-500" />
                        <span class="h-3 w-24 rounded-full bg-gray-200 motion-safe:animate-pulse dark:bg-white/10"></span>
                    </span>

                    <x-filament::loading-indicator class="h-4 w-4 text-gray-400" />
                </div>

                <div class="shrink-0 divide-y divide-gray-100 px-4 text-sm dark:divide-white/5">
                    @foreach (['from' => 'w-56', 'to' => 'w-40', 'subject' => 'w-72'] as $field => $width)
                        <div class="flex h-10 items-center gap-3">
                            <span class="w-14 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">{{ __("filament/emails/composer.fields.{$field}") }}</span>
                            <span class="{{ $width }} h-3 rounded-full bg-gray-100 motion-safe:animate-pulse dark:bg-white/5"></span>
                        </div>
                    @endforeach
                </div>

                <div class="mx-4 h-12 shrink-0 border-b border-gray-100 dark:border-white/5"></div>

                <div class="flex-1"></div>

                <div class="flex h-14 shrink-0 items-center justify-end border-t border-gray-200 px-3 dark:border-white/10 sm:px-5">
                    <span class="h-9 w-32 rounded-lg bg-gray-100 motion-safe:animate-pulse dark:bg-white/5"></span>
                </div>
            </div>
        </div>
    @endif

    @if ($isOpen)
        {{-- Esc puts the draft away like the window's ×. Anything open inside it (a
             suggestion list, a menu, a modal) takes the key first. --}}
        <div
            x-data="{
                closing: false,
                closeOnEscape(event) {
                    if (event.defaultPrevented || document.querySelector('.fi-modal-open, .fi-dropdown-panel[style*=\'display: block\']')) {
                        return
                    }

                    event.preventDefault()
                    this.closing = true
                    this.$wire.close()
                },
            }"
            x-show="! closing"
            @if ($isModal)
                x-on:keydown.escape.document="closeOnEscape($event)"
            @elseif ($dock === 'floating')
                x-on:keydown.escape="closeOnEscape($event)"
            @endif
        >
            @if ($isModal)
                {{-- Backdrop click minimises rather than closes: the corner window keeps the
                     compose session in progress and in sight, where closing would file a draft. --}}
                <div wire:click="minimize" class="fixed inset-0 z-40 bg-gray-950/50"></div>
            @endif

            <div
                x-data
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="translate-y-4 opacity-0"
                x-transition:enter-end="translate-y-0 opacity-100"
                @class([
                    'flex flex-col bg-white dark:bg-gray-900',
                    'fixed z-40 overflow-hidden rounded-xl shadow-2xl shadow-gray-950/20 ring-1 ring-gray-950/10 transition-all duration-200 dark:shadow-black/50 dark:ring-white/10' => $isFloating,
                    'bottom-4 right-4' => $isFloating && ($isMinimized || ! $isExpanded),
                    'w-[38rem] h-[36rem]' => $isFloating && ! $isMinimized && ! $isExpanded,
                    'w-80' => $isFloating && $isMinimized,
                    // Matches the reader's panel: centred, same width and height.
                    'left-1/2 top-1/2 z-50 h-[85vh] w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2' => $isModal,
                    'max-w-5xl' => $isModal && ! $isMassSendLayout,
                    'max-w-6xl' => $isModal && $isMassSendLayout,
                ])
            >
                {{-- Title bar. The inline dock sits inside the message it answers, so it
                     needs no window chrome, just the Draft bar, whose × discards. --}}
                @if ($dock === 'inline')
                    <x-email-integration::composer-draft-bar class="sticky top-0 z-10 h-10 border-t-2 border-dashed border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-900 sm:px-6" />
                @else
                    <div @class([
                        'flex h-12 shrink-0 items-center justify-between gap-2 bg-gray-50 pl-4 pr-2 dark:bg-white/5',
                        'border-b border-gray-200 dark:border-white/10' => ! $isMinimized,
                    ])>
                        <button
                            type="button"
                            wire:click="{{ $isMinimized ? 'restore' : 'minimize' }}"
                            class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm font-medium text-gray-900 dark:text-gray-100"
                        >
                            <x-heroicon-m-envelope class="h-4 w-4 shrink-0 text-primary-500" />
                            <span class="truncate">{{ filled($subject) ? $subject : ($isMassSend ? __('filament/emails/composer.title_mass_send') : __('filament/emails/composer.title')) }}</span>
                        </button>

                        <div class="flex shrink-0 items-center gap-0.5">
                            <button
                                type="button"
                                wire:click="{{ $isMinimized ? 'restore' : 'minimize' }}"
                                aria-label="{{ $minimizeLabel }}"
                                x-tooltip="{ content: @js($minimizeLabel), theme: $store.theme }"
                                class="{{ $chromeButton }}"
                            >
                                <x-dynamic-component :component="$isMinimized ? 'heroicon-m-chevron-up' : 'heroicon-m-minus'" class="h-4 w-4" />
                            </button>

                            {{-- No fit-to-screen toggle while the message being answered is
                                 previewed above the draft: shrinking to the corner window
                                 drops the preview, so the control would only ever throw away
                                 what this view exists to show. --}}
                            @unless ($isMinimized || $this->sourceEmail !== null)
                                <button
                                    type="button"
                                    wire:click="toggleExpand"
                                    aria-label="{{ $expandLabel }}"
                                    x-tooltip="{ content: @js($expandLabel), theme: $store.theme }"
                                    class="{{ $chromeButton }}"
                                >
                                    <x-dynamic-component
                                        :component="$isExpanded ? 'heroicon-m-arrows-pointing-in' : 'heroicon-m-arrows-pointing-out'"
                                        class="h-4 w-4"
                                    />
                                </button>
                            @endunless

                            {{-- This × puts the draft away and keeps it. Only a Draft bar's
                                 × deletes, including a row a previous save already wrote. --}}
                            <button
                                type="button"
                                wire:click="close"
                                x-on:click="closing = true"
                                aria-label="{{ $closeLabel }}"
                                x-tooltip="{ content: @js($closeLabel), theme: $store.theme }"
                                class="{{ $chromeButton }}"
                            >
                                <x-heroicon-m-x-mark class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                @endif

                @unless ($isMinimized)
                    @if (! $this->canSendFromSelectedAccount())
                        <x-email-integration::composer-send-missing :email="$this->fromAccount?->email_address">
                            {{ $this->grantSendPermissionAction }}
                        </x-email-integration::composer-send-missing>
                    @else
                    {{-- The message being answered or forwarded, above the draft and split
                         off by the same dashed rule the inline dock uses, so a reply looks
                         the same whether it is being written under the original or reopened
                         later from Drafts. The dock never shows this: there, the real
                         message is already on screen right above it. --}}
                    @if ($isModal && $this->sourceEmail !== null)
                        <div class="min-h-0 flex-1 overflow-y-auto border-b-2 border-dashed border-gray-200 dark:border-gray-700">
                            <x-email-integration::quoted-message :record="$this->sourceEmail" />
                        </div>

                        <x-email-integration::composer-draft-bar class="h-10 px-4" />
                    @endif

                    @if ($isFloatingExpanded)
                        <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
                    @endif
                    @if ($isMassSendLayout)
                        <div class="flex min-h-0 flex-1 overflow-hidden">
                            <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                    @endif

                    {{-- Field rows --}}
                    <x-email-integration::composer-header
                        class="{{ $gutter }}"
                        :is-mass-send="$isMassSend"
                        :mass-recipients="$massRecipients"
                        :show-cc="$showCc"
                        :show-bcc="$showBcc"
                        :from-avatar-url="$this->fromAvatarUrl"
                        :account-options="$this->accountOptions"
                        :from-account="$this->fromAccount"
                        :recipient-suggestions="$this->recipientSuggestions"
                        :recipient-options="$this->recipientOptions"
                    />

                    {{-- Body: Filament RichEditor with floating toolbar only --}}
                    <div @class([
                        'email-composer-body-ctn px-4',
                        'min-h-0 flex-1 overflow-y-auto pb-3' => $isFloating,
                        'shrink-0 pb-2 sm:px-6' => ! $isFloating,
                    ]) @if ($dock === 'inline') data-composer-dock="inline" @endif wire:ignore>
                        {{ $this->getSchema('bodySchema') }}
                    </div>
                    @error('bodyHtml')
                        <p class="px-4 pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror

                    <x-email-integration::composer-attachments :saved="$savedAttachments" :pending="$attachments" />

                    @if ($isMassSendLayout)
                            </div>
                            <x-email-integration::composer-mass-recipients :recipients="$massRecipients" :options="$this->recipientOptions" />
                        </div>
                    @endif
                    @if ($isFloatingExpanded)
                        </div>
                    @endif

                    {{-- Bottom bar --}}
                    <div @class([
                        'flex shrink-0 flex-col gap-2 border-t border-gray-200 px-3 dark:border-white/10 sm:px-5',
                        'sticky bottom-0 z-10 bg-white dark:bg-gray-900' => ! $isFloating,
                        'py-3' => $showMassSendToggle && $isMassSend,
                        'h-14 justify-center' => ! ($showMassSendToggle && $isMassSend),
                    ])>
                        @if ($showMassSendToggle && $isMassSend)
                            <x-email-integration::composer-mass-send-outbox-hint />
                        @endif

                        <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-1">
                            <x-email-integration::composer-icon-button icon="heroicon-o-paper-clip" :label="__('filament/emails/composer.actions.attach')" x-on:click="$refs.attachments.click()" />
                            <input type="file" x-ref="attachments" wire:model="attachments" multiple class="hidden" />

                            <x-email-integration::composer-picker-menu
                                icon="heroicon-o-pencil-square"
                                :label="__('filament/emails/composer.actions.signature')"
                                :options="$this->signatureOptions"
                                :selected="$signatureId"
                                :none-label="__('filament/emails/composer.fields.signature_none')"
                                :click="fn (?string $id): string => $id === null ? '$set(\'signatureId\', null)' : '$set(\'signatureId\', \''.$id.'\')'"
                                :create-label="__('filament/emails/composer.actions.create_signature')"
                                create-click="mountAction('createSignature')"
                            />

                            <x-email-integration::composer-picker-menu
                                icon="heroicon-o-document-text"
                                :label="__('filament/emails/composer.actions.template')"
                                :options="$this->templateOptions"
                                :empty-label="__('filament/emails/composer.fields.template_none')"
                                :click="fn (?string $id): string => 'applyTemplate(\''.$id.'\')'"
                                :create-label="__('filament/emails/composer.actions.create_template')"
                                create-click="mountAction('createTemplate')"
                            />

                            <span wire:loading wire:target="attachments" class="pl-1 text-xs text-gray-400">{{ __('filament/emails/composer.actions.uploading') }}</span>
                        </div>

                        <div class="flex items-center gap-3">
                            <x-email-integration::composer-mass-send-footer-actions
                                :show-toggle="$showMassSendToggle"
                                :is-mass-send="$isMassSend"
                                :recipient-count="count($massRecipients)"
                            />

                        <x-filament::button
                            wire:click="send"
                            wire:loading.attr="disabled"
                            wire:target="send, attachments"
                            icon="heroicon-m-paper-airplane"
                        >
                            @if ($isMassSend)
                                {{ __('filament/emails/composer.mass_send.send_button', ['count' => count($massRecipients)]) }}
                            @else
                                {{ __('filament/emails/composer.actions.send') }}
                            @endif
                        </x-filament::button>
                        </div>
                        </div>
                    </div>
                    @endif
                @endunless
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
