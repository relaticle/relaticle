@props([
    'options' => [],
    'suggestions' => [],
    'allowedAddresses' => [],
    'autofocus' => false,
])

@php
    use Illuminate\Support\Str;

    $wireModel = $attributes->wire('model')->value();
    $listboxId = Str::slug($wireModel ?: 'recipients').'-suggestions';
    $removeLabel = __('filament/emails/composer.actions.remove_recipient');
    $companyTeamLabel = __('filament/emails/composer.fields.company_team_count');
    $avatarColor = static fn (string $name): string => ['primary', 'success', 'warning', 'danger', 'info'][abs(crc32(mb_strtolower($name))) % 5];
    $manualOptions = collect($suggestions)
        ->map(fn (string $suggestion): array => [
            'type' => 'email',
            'id' => $suggestion,
            'label' => $suggestion,
            'description' => null,
            'email' => $suggestion,
            'avatarUrl' => null,
            'iconPath' => null,
            'circular' => true,
            'avatarColor' => $avatarColor($suggestion),
        ])
        ->all();
@endphp

<div
    x-data="{
        values: $wire.$entangle('{{ $wireModel }}'),
        newValue: '',
        activeIndex: 0,
        popoverStyle: {},
        options: @js([...$options, ...$manualOptions]),
        allowedAddresses: @js($allowedAddresses),
        removeLabel: @js($removeLabel),
        companyTeamLabel: @js($companyTeamLabel),

        get matches() {
            const query = this.newValue.trim().toLowerCase();

            if (query === '') {
                return [];
            }

            return this.options
                .filter((option) => ! this.optionSelected(option))
                .filter((option) => [option.label, option.description, option.email]
                    .filter(Boolean)
                    .some((value) => value.toLowerCase().includes(query)))
                .slice(0, 8);
        },

        get selectedChips() {
            return this.values.map((value) => ({ value, ...this.chipFor(value) }));
        },

        chipFor(value) {
            const normalized = value.toLowerCase();
            const option = this.options.find((candidate) => (candidate.email ?? '').toLowerCase() === normalized);

            if (option) {
                return option;
            }

            return {
                type: 'email',
                id: value,
                label: value,
                email: value,
                avatarUrl: null,
                iconPath: null,
                circular: true,
                avatarColor: 'primary',
            };
        },

        initials(name) {
            return String(name ?? '')
                .trim()
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map((word) => word.charAt(0).toUpperCase())
                .join('') || '?';
        },

        chipTooltip(chip) {
            if (chip.email && chip.label && chip.label !== chip.email) {
                return chip.label + ' · ' + chip.email;
            }

            return chip.email || chip.label || '';
        },

        get isOpen() {
            return this.matches.length > 0;
        },

        updatePopover() {
            const rect = this.$refs.input.getBoundingClientRect();
            const width = Math.min(320, window.innerWidth - 24);
            const left = Math.min(Math.max(rect.left, 12), window.innerWidth - width - 12);

            this.popoverStyle = {
                left: left + 'px',
                top: rect.bottom + 6 + 'px',
                width: width + 'px',
            };
        },

        allowedEmail(raw = null) {
            const value = (raw ?? this.newValue).trim().replace(/,$/, '');

            if (! value) {
                return null;
            }

            const normalized = value.toLowerCase();

            return this.allowedAddresses.find((email) => email.toLowerCase() === normalized) ?? null;
        },

        resolveEmail(raw = null) {
            return this.resolveEmailFromOptions(raw) ?? this.allowedEmail(raw);
        },

        resolveEmailFromOptions(raw = null) {
            const value = (raw ?? this.newValue).trim().replace(/,$/, '');

            if (! value) {
                return null;
            }

            const normalized = value.toLowerCase();

            const option = this.options.find((candidate) => {
                if (candidate.type === 'company_team') {
                    return (candidate.emails ?? []).some((email) => email.toLowerCase() === normalized);
                }

                return (candidate.email ?? '').toLowerCase() === normalized;
            });

            if (! option) {
                return null;
            }

            if (option.type === 'company_team') {
                return (option.emails ?? []).find((email) => email.toLowerCase() === normalized) ?? null;
            }

            return option.email;
        },

        commit(raw = null) {
            const value = this.resolveEmail(raw);

            if (! value) {
                this.newValue = '';

                return;
            }

            if (! this.values.includes(value)) {
                this.values = [...this.values, value];
            }

            this.newValue = '';
            this.activeIndex = 0;
        },

        addEmails(emails) {
            const nextValues = [...this.values];

            emails.forEach((email) => {
                if (! nextValues.includes(email)) {
                    nextValues.push(email);
                }
            });

            this.values = nextValues;
            this.newValue = '';
            this.activeIndex = 0;
        },

        choose(option) {
            if (option.type === 'company_team') {
                this.addEmails(option.emails ?? []);

                return;
            }

            this.commit(option.email);
        },

        optionSelected(option) {
            if (option.type === 'company_team') {
                return (option.emails ?? []).every((email) => this.values.includes(email));
            }

            return this.values.includes(option.email);
        },

        removeLast() {
            if (this.newValue !== '') {
                return;
            }

            this.values = this.values.slice(0, -1);
        },

        remove(value) {
            this.values = this.values.filter((v) => v !== value);
        },

        handleKeydown(event) {
            if (event.key === 'ArrowDown' && this.isOpen) {
                event.preventDefault();
                this.activeIndex = Math.min(this.activeIndex + 1, this.matches.length - 1);

                return;
            }

            if (event.key === 'ArrowUp' && this.isOpen) {
                event.preventDefault();
                this.activeIndex = Math.max(this.activeIndex - 1, 0);

                return;
            }

            if (event.key === 'Escape') {
                this.activeIndex = 0;
                this.newValue = '';

                return;
            }

            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();

                if (this.isOpen && event.key === 'Enter') {
                    this.choose(this.matches[this.activeIndex]);

                    return;
                }

                this.commit();

                return;
            }

            if (event.key === 'Tab' && this.newValue.trim() !== '') {
                event.preventDefault();
                this.commit();

                return;
            }

            if (event.key === 'Backspace' && this.newValue === '') {
                this.removeLast();
            }
        },
    }"
    x-effect="if (isOpen) updatePopover()"
    x-on:resize.window="updatePopover()"
    x-on:scroll.window="updatePopover()"
    @if ($autofocus)
        x-init="$nextTick(() => $refs.input.focus())"
    @endif
    @if ($wireModel) wire:ignore @endif
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'flex min-h-10 min-w-0 flex-wrap content-start items-center gap-2 py-2']) }}
>
    <template x-for="chip in selectedChips" :key="chip.value">
        <span
            class="inline-flex max-w-48 items-center gap-1 rounded-full bg-gray-100 py-0.5 pl-0.5 pr-0.5 text-[11px] font-medium leading-4 text-gray-800 ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10"
            x-tooltip="{ content: chipTooltip(chip), theme: $store.theme }"
        >
            <x-emails.recipient-avatar box="size-4" glyph="size-2.5" initials-size="text-[9px]" />
            <span class="min-w-0 truncate" x-text="chip.label"></span>
            <button type="button" x-on:click="remove(chip.value)" :aria-label="removeLabel + ': ' + chip.value" class="shrink-0 rounded-full p-px text-gray-400 transition hover:bg-gray-950/5 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200">
                <x-heroicon-m-x-mark class="h-2.5 w-2.5" />
            </button>
        </span>
    </template>

    <input
        x-ref="input"
        type="text"
        x-model="newValue"
        x-on:input="updatePopover()"
        x-on:focus="updatePopover()"
        x-on:keydown="handleKeydown($event)"
        x-on:blur="commit()"
        x-bind:aria-expanded="isOpen"
        x-bind:aria-controls="'{{ $listboxId }}'"
        role="combobox"
        autocomplete="off"
        @if ($autofocus) autofocus @endif
        class="h-6 min-w-[8rem] flex-1 border-0 bg-transparent p-0 text-sm leading-6 focus:outline-none focus:ring-0"
    />

    <template x-teleport="body">
        <div
            x-show="isOpen"
            x-cloak
            x-on:mousedown.prevent
            x-bind:style="popoverStyle"
            id="{{ $listboxId }}"
            role="listbox"
            class="fixed z-50 max-h-72 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg ring-1 ring-black/5 dark:border-white/10 dark:bg-gray-900 dark:ring-white/10"
        >
            <template x-for="(chip, index) in matches" :key="chip.type + ':' + chip.id">
                <button
                    type="button"
                    role="option"
                    x-on:click="choose(chip)"
                    x-bind:aria-selected="index === activeIndex"
                    class="flex min-h-11 w-full items-center gap-2.5 px-2.5 py-2 text-left transition focus:outline-none"
                    x-bind:class="index === activeIndex ? 'bg-gray-100 dark:bg-white/10' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                >
                    <x-emails.recipient-avatar box="size-7" glyph="size-4" initials-size="text-[11px]" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="chip.label"></span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="chip.description"></span>
                    </span>
                    <span
                        x-show="chip.type === 'company_team'"
                        class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300"
                        x-text="companyTeamLabel + ' (' + chip.count + ')'"
                    ></span>
                </button>
            </template>
        </div>
    </template>
</div>
