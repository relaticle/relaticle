@props([
    'options' => [],
    'suggestions' => [],
    'autofocus' => false,
])

@php
    use Illuminate\Support\Str;

    $wireModel = $attributes->wire('model')->value();
    $listboxId = Str::slug($wireModel ?: 'recipients').'-suggestions';
    $removeLabel = __('filament/emails/composer.actions.remove_recipient');
    $companyTeamLabel = __('filament/emails/composer.fields.company_team_count');
    $manualOptions = collect($suggestions)
        ->map(fn (string $suggestion): array => [
            'type' => 'email',
            'id' => $suggestion,
            'label' => $suggestion,
            'description' => null,
            'email' => $suggestion,
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

        commit(raw = null) {
            const value = (raw ?? this.newValue).trim().replace(/,$/, '');

            if (! value) {
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
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'flex min-h-[1.75rem] min-w-0 flex-wrap items-center gap-1']) }}
>
    <template x-for="value in values" :key="value">
        <span class="inline-flex max-w-full items-center gap-1 rounded-full bg-primary-50 py-0.5 pl-2 pr-1 text-xs font-medium text-primary-700 ring-1 ring-primary-600/10 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/20">
            <span class="truncate" x-text="value"></span>
            <button type="button" x-on:click="remove(value)" :aria-label="removeLabel + ': ' + value" class="shrink-0 rounded-full p-0.5 text-primary-400 transition hover:bg-primary-600/10 hover:text-primary-700 dark:hover:text-primary-200">
                <x-heroicon-m-x-mark class="h-3 w-3" />
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
        class="min-w-[8rem] flex-1 border-0 bg-transparent p-0 text-sm focus:outline-none focus:ring-0"
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
            <template x-for="(option, index) in matches" :key="option.type + ':' + option.id">
                <button
                    type="button"
                    role="option"
                    x-on:click="choose(option)"
                    x-bind:aria-selected="index === activeIndex"
                    class="flex min-h-11 w-full items-center gap-2.5 px-2.5 py-2 text-left transition focus:outline-none"
                    x-bind:class="index === activeIndex ? 'bg-gray-100 dark:bg-white/10' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                >
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300" aria-hidden="true">
                        <x-heroicon-o-user x-show="option.type === 'person'" class="h-4 w-4" />
                        <x-heroicon-o-building-office-2 x-show="option.type === 'company_team'" class="h-4 w-4" />
                        <x-heroicon-o-envelope x-show="option.type === 'email'" class="h-4 w-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="option.label"></span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="option.description"></span>
                    </span>
                    <span
                        x-show="option.type === 'company_team'"
                        class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300"
                        x-text="companyTeamLabel + ' (' + option.count + ')'"
                    ></span>
                </button>
            </template>
        </div>
    </template>
</div>
