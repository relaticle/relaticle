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
            openingExpanded: false,
            shortcut: null,
            init() {
                $wire.$interceptMessage(({ onFinish }) => onFinish(() => this.opening = false))
                this.shortcut = this.$store.shortcuts?.register('email-compose', {
                    scope: 'global',
                    entries: @js(\Relaticle\EmailIntegration\Enums\EmailShortcut::forStore()),
                    handlers: { compose: () => $wire.dispatch('composer:open') },
                })
            },
            destroy() {
                this.$store.shortcuts?.unregister('email-compose', this.shortcut)
            },
        }"
        x-on:composer:open.window="opening = ! $wire.isOpen; openingExpanded = Boolean($event.detail?.payload?.massSend)"
    @endif
>
    @php
        $isModal = $dock === 'floating' && $isExpanded && ! $isMinimized;
        $isFloating = $dock === 'floating';
        $isFloatingExpanded = $isFloating && $isExpanded && ! $isMinimized;
        $isMassSendLayout = $isFloatingExpanded && $isMassSend;
        $showMassSendToggle = $isFloating && ! $isMinimized && $this->sourceEmail === null;
        $gutter = $isFloating ? 'px-4' : 'px-4 sm:px-6';
        $chromeButton = 'rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-200/70 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200';
        $minimizeLabel = $isMinimized ? __('filament/emails/composer.actions.restore') : __('filament/emails/composer.actions.minimize');
        $expandLabel = $isExpanded ? __('filament/emails/composer.actions.shrink') : __('filament/emails/composer.actions.expand');
        $closeLabel = __('filament/emails/composer.actions.close');
    @endphp

    @if ($dock === 'floating' && ! $isOpen)
        <div x-cloak x-show="opening" role="status" aria-label="{{ __('filament/emails/composer.opening') }}">
            <div x-show="openingExpanded" class="ei-composer-opening-backdrop fixed inset-0 z-40 bg-gray-950/50"></div>

            <div
                :class="openingExpanded ? 'left-1/2 top-1/2 h-[85vh] w-[calc(100%-2rem)] max-w-6xl -translate-x-1/2 -translate-y-1/2' : 'bottom-4 right-4 h-[36rem] w-[38rem]'"
                class="ei-composer-opening-panel fixed z-50 flex flex-col overflow-hidden rounded-xl max-sm:inset-0 max-sm:h-dvh max-sm:w-full max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none max-sm:pt-[env(safe-area-inset-top)] bg-white shadow-2xl shadow-gray-950/20 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:shadow-black/50 dark:ring-white/10"
            >
                <div class="flex h-12 shrink-0 items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 pl-4 pr-4 dark:border-white/10 dark:bg-white/5">
                    <span class="flex items-center gap-2">
                        <x-lucide-mail class="h-4 w-4 shrink-0 text-primary-500" />
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
                autosaveTimer: null,
                shortcut: null,
                init() {
                    this.$nextTick(() => this.focusFirstEmptyField())
                    this.shortcut = this.$store.shortcuts?.register('email-composer-{{ $dock }}', {
                        scope: 'composer',
                        entries: @js(\Relaticle\EmailIntegration\Enums\EmailShortcut::forStore()),
                        handlers: {
                            send: () => this.send(),
                            sendLater: () => this.showSendLater(),
                            draftWithRela: () => $wire.dispatch('rela:focus', { composerId: $wire.$id }),
                        },
                        isActive: () => ! this.closing && ! this.$wire.isMinimized && this.$el.contains(document.activeElement),
                    })
                    for (const field of ['to', 'cc', 'bcc', 'accountId']) {
                        this.$watch('$wire.' + field, () => this.queueAutosave())
                    }
                },
                destroy() {
                    clearTimeout(this.autosaveTimer)
                    this.$store.shortcuts?.unregister('email-composer-{{ $dock }}', this.shortcut)
                },
                send() {
                    clearTimeout(this.autosaveTimer)
                    this.autosaveTimer = null
                    this.$wire.send()
                },
                showSendLater() {
                    const trigger = this.$el.querySelector('[data-send-later-trigger]')
                    if (! trigger) return false
                    Alpine.$data(trigger.closest('.fi-dropdown')).open(trigger)
                },
                queueAutosave() {
                    clearTimeout(this.autosaveTimer)
                    if (this.$wire.relaWriting) return
                    this.autosaveTimer = setTimeout(() => this.flushAutosave(), 2000)
                },
                async flushAutosave() {
                    if (! this.autosaveTimer || this.closing || ['stale', 'gone'].includes(this.$wire.autosaveState)) return
                    clearTimeout(this.autosaveTimer)
                    this.autosaveTimer = null
                    await this.$wire.autosave()
                },
                focusFirstEmptyField() {
                    const recipient = this.$el.querySelector('[data-composer-to] input')

                    if (recipient && this.$wire.to.length === 0) {
                        return recipient.focus()
                    }

                    const subject = this.$el.querySelector('#email-composer-subject')

                    if (subject && ! this.$wire.subject) {
                        return subject.focus()
                    }

                    this.focusBodyOnceMounted()
                },
                focusBodyOnceMounted() {
                    const body = this.$el.querySelector('.email-composer-body-ctn')
                    const focusEditor = () => {
                        const editor = body?.querySelector('.tiptap[contenteditable=\'true\']')

                        editor?.focus()

                        return Boolean(editor)
                    }

                    if (! body || focusEditor()) {
                        return
                    }

                    const observer = new MutationObserver(() => focusEditor() && observer.disconnect())

                    observer.observe(body, { childList: true, subtree: true, attributes: true, attributeFilter: ['contenteditable'] })
                },
                async closeOnEscape(event) {
                    if (event.defaultPrevented || document.querySelector('.fi-modal-open, .fi-dropdown-panel[style*=\'display: block\']')) {
                        return
                    }

                    event.preventDefault()
                    this.closing = true
                    clearTimeout(this.autosaveTimer)
                    await this.$wire.close()
                    this.closing = false
                },
            }"
            x-on:input="queueAutosave()"
            x-on:blur.window="flushAutosave()"
            x-on:visibilitychange.document="if (document.hidden) flushAutosave()"
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
                    // A phone has no room for a corner window, so an open composer takes the screen.
                    'max-sm:inset-0 max-sm:h-dvh max-sm:w-full max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none max-sm:pt-[env(safe-area-inset-top)] max-sm:pb-[env(safe-area-inset-bottom)] max-sm:ring-0' => $isFloating && ! $isMinimized,
                    'max-sm:inset-x-2 max-sm:bottom-[calc(var(--bottom-nav-height)+env(safe-area-inset-bottom)+0.5rem)] max-sm:w-auto' => $isFloating && $isMinimized,
                ])
            >
                {{-- Title bar. The inline dock sits inside the message it answers, so it
                     needs no window chrome, just the Draft bar, whose × discards. --}}
                @if ($dock === 'inline')
                    <x-email-integration::composer-draft-bar :autosave-state="$autosaveState" class="sticky top-0 z-10 h-10 border-t-2 border-dashed border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-900 sm:px-6" />
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
                            <x-lucide-mail class="h-4 w-4 shrink-0 text-primary-500" />
                            <span class="truncate">{{ filled($subject) ? $subject : ($isMassSend ? __('filament/emails/composer.title_mass_send') : __('filament/emails/composer.title')) }}</span>
                        </button>

                        <div class="flex shrink-0 items-center gap-0.5">
                            <span wire:loading wire:target="autosave" class="px-2 text-xs text-gray-500 dark:text-gray-400">{{ __('filament/emails/composer.autosave.saving') }}</span>
                            @if ($autosaveState === 'saved')
                                <span wire:loading.remove wire:target="autosave" class="px-2 text-xs text-gray-500 dark:text-gray-400">{{ __('filament/emails/composer.autosave.saved') }}</span>
                            @endif
                            <button
                                type="button"
                                wire:click="{{ $isMinimized ? 'restore' : 'minimize' }}"
                                aria-label="{{ $minimizeLabel }}"
                                x-tooltip="{ content: @js($minimizeLabel), theme: $store.theme }"
                                class="{{ $chromeButton }}"
                            >
                                <x-dynamic-component :component="$isMinimized ? 'lucide-chevron-up' : 'lucide-minus'" class="h-4 w-4" />
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
                                    {{-- A phone shows both sizes full screen, so shrinking would only drop mass sending. --}}
                                    class="{{ $chromeButton }} max-sm:hidden"
                                >
                                    <x-dynamic-component
                                        :component="$isExpanded ? 'lucide-minimize-2' : 'lucide-maximize-2'"
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
                                <x-lucide-x class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                @endif

                @unless ($isMinimized)
                    @if (in_array($autosaveState, ['stale', 'gone'], true))
                        <div role="alert" class="flex flex-wrap items-center gap-2 border-b border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-900 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-200">
                            <span>{{ __('filament/emails/composer.autosave.'.$autosaveState) }}</span>
                            @if ($autosaveState === 'stale')
                                <x-filament::button size="xs" color="gray" wire:click="reloadDraft">{{ __('filament/emails/composer.autosave.reload') }}</x-filament::button>
                            @endif
                            <x-filament::button size="xs" color="gray" wire:click="saveAsNewDraft">{{ __('filament/emails/composer.autosave.save_as_new') }}</x-filament::button>
                        </div>
                    @endif
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

                        <x-email-integration::composer-draft-bar :autosave-state="$autosaveState" class="h-10 px-4" />
                    @endif

                    @if ($isFloatingExpanded)
                        <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
                    @endif
                    @if ($isMassSendLayout)
                        <div class="flex min-h-0 flex-1 overflow-hidden max-md:flex-col max-md:overflow-y-auto">
                            <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden max-md:flex-none max-md:overflow-visible">
                    @endif

                    {{-- Field rows --}}
                    <x-email-integration::composer-header
                        x-bind:inert="$wire.relaWriting"
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

                    @if (! $isMassSend)
                        <livewire:chat.email-rela-draft :composer-id="$this->getId()" :current-draft-id="$draftId" :key="'rela-'.$this->getId()" />
                    @endif

                    {{-- Body: Filament RichEditor with floating toolbar only --}}
                    <div @class([
                        'email-composer-body-ctn px-4',
                        'min-h-0 flex-1 overflow-y-auto pb-3' => $isFloating,
                        'shrink-0 pb-2 sm:px-6' => ! $isFloating,
                    ]) x-bind:inert="$wire.relaWriting" x-bind:aria-busy="$wire.relaWriting" @if ($dock === 'inline') data-composer-dock="inline" @endif wire:ignore>
                        {{ $this->getSchema('bodySchema') }}
                    </div>
                    @error('bodyHtml')
                        <p class="px-4 pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror

                    @if ($this->variablePreview)
                        @php($preview = $this->variablePreview)
                        <section aria-label="{{ __('filament/emails/composer.variables.preview') }}" class="space-y-2 border-t border-gray-200 p-4 dark:border-white/10">
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <span>{{ __('filament/emails/composer.variables.preview_position', ['index' => $preview['index'] + 1, 'count' => $preview['count']]) }}</span>
                                <div class="flex gap-2">
                                    <x-filament::icon-button icon="lucide-chevron-left" :label="__('pagination.previous')" wire:click="$set('previewIndex', {{ max(0, $preview['index'] - 1) }})" :disabled="$preview['index'] === 0" />
                                    <x-filament::icon-button icon="lucide-chevron-right" :label="__('pagination.next')" wire:click="$set('previewIndex', {{ min($preview['count'] - 1, $preview['index'] + 1) }})" :disabled="$preview['index'] + 1 === $preview['count']" />
                                    <x-filament::icon-button icon="lucide-x" :label="__('filament/emails/composer.actions.close')" wire:click="$set('showVariablePreview', false)" />
                                </div>
                            </div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $preview['email'] }}</p>
                            <p class="text-sm font-medium">{{ $preview['subject'] }}</p>
                            <iframe title="{{ __('filament/emails/composer.variables.preview') }}" srcdoc="{{ $preview['html'] }}" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" class="h-64 w-full rounded border border-gray-200 bg-white dark:border-white/10"></iframe>
                        </section>
                    @endif

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
                        'h-14 justify-center max-sm:h-auto max-sm:py-3' => ! ($showMassSendToggle && $isMassSend),
                    ])>
                        @if ($showMassSendToggle && $isMassSend)
                            <x-email-integration::composer-mass-send-outbox-hint />
                        @endif

                        {{-- A phone has no room for one row, so Send takes a full-width row of its own under the tools. --}}
                        <div class="flex items-center justify-between gap-3 max-sm:flex-wrap max-sm:gap-y-3">
                        <div class="flex items-center gap-1">
                            <x-email-integration::composer-icon-button icon="lucide-paperclip" :label="__('filament/emails/composer.actions.attach_file')" x-on:click="$refs.attachments.click()" />
                            <input type="file" x-ref="attachments" wire:model="attachments" multiple class="hidden" />
                            <x-email-integration::composer-icon-button icon="lucide-eye" :label="__('filament/emails/composer.variables.preview')" wire:click="$toggle('showVariablePreview')" />

                            <x-email-integration::composer-picker-menu
                                icon="lucide-square-pen"
                                :label="__('filament/emails/composer.actions.signature')"
                                :options="$this->signatureOptions"
                                :selected="$signatureId"
                                :none-label="__('filament/emails/composer.fields.signature_none')"
                                :click="fn (?string $id): string => $id === null ? '$set(\'signatureId\', null)' : '$set(\'signatureId\', \''.$id.'\')'"
                                :create-label="__('filament/emails/composer.actions.create_signature')"
                                create-click="mountAction('createSignature')"
                            />

                            <x-email-integration::composer-picker-menu
                                icon="lucide-file-text"
                                :label="__('filament/emails/composer.actions.template')"
                                :options="$this->templateOptions"
                                :empty-label="__('filament/emails/composer.fields.template_none')"
                                :click="fn (?string $id): string => 'applyTemplate(\''.$id.'\')'"
                                :create-label="__('filament/emails/composer.actions.create_template')"
                                create-click="mountAction('createTemplate')"
                            />

                            <span wire:loading wire:target="attachments" class="pl-1 text-xs text-gray-400">{{ __('filament/emails/composer.actions.uploading') }}</span>
                        </div>

                        <div class="flex items-center gap-3 max-sm:contents">
                            @if (! $isMassSend)
                                <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                    <x-filament::input.checkbox wire:model="followUp" />
                                    {{ __('filament/emails/composer.follow_up.toggle') }}
                                </label>
                            @endif
                            <x-email-integration::composer-mass-send-footer-actions
                                :show-toggle="$showMassSendToggle"
                                :is-mass-send="$isMassSend"
                                :recipient-count="count($massRecipients)"
                            />

                        <div class="flex items-stretch max-sm:basis-full">
                        <x-filament::button
                            wire:click="send"
                            x-on:click="clearTimeout(autosaveTimer); autosaveTimer = null"
                            wire:loading.attr="disabled"
                            wire:target="send, attachments"
                            icon="lucide-send"
                            @class(['max-sm:flex-1', 'rounded-r-none' => ! $isMassSend])
                        >
                            @if ($isMassSend)
                                {{ __('filament/emails/composer.mass_send.send_button', ['count' => count($massRecipients)]) }}
                            @else
                                {{ __('filament/emails/composer.actions.send') }}
                            @endif
                        </x-filament::button>
                        @unless ($isMassSend)
                            <x-filament::dropdown placement="top-end">
                                <x-slot name="trigger">
                                    <x-filament::button
                                        data-send-later-trigger
                                        aria-label="{{ __('filament/emails/composer.send_later.title') }}"
                                        class="h-full rounded-l-none border-l border-white/20"
                                    >
                                        <x-lucide-chevron-down class="size-4" />
                                    </x-filament::button>
                                </x-slot>
                                <x-filament::dropdown.list>
                                    @foreach ($this->sendLaterOptions as $preset => $label)
                                        <x-filament::dropdown.list.item wire:click="sendLater('{{ $preset }}')" x-on:click="clearTimeout(autosaveTimer); autosaveTimer = null" icon="lucide-clock">
                                            {{ $label }}
                                        </x-filament::dropdown.list.item>
                                    @endforeach
                                    <x-filament::dropdown.list.item wire:click="mountAction('sendLater')" icon="lucide-calendar-days">
                                        {{ __('filament/emails/composer.send_later.custom') }}
                                    </x-filament::dropdown.list.item>
                                </x-filament::dropdown.list>
                                <p class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
                                    @if (auth()->user()->timezone)
                                        {{ __('filament/emails/composer.send_later.timezone', ['timezone' => auth()->user()->timezone]) }}
                                    @else
                                        <a href="{{ \App\Filament\Pages\EditProfile::getUrl() }}" class="text-primary-600 dark:text-primary-400">{{ __('filament/emails/composer.send_later.utc_notice') }}</a>
                                    @endif
                                </p>
                            </x-filament::dropdown>
                        @endunless
                        </div>
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
