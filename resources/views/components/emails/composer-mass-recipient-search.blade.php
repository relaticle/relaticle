@props([
    'options' => [],
    'selectedPersonIds' => [],
])

@php
    $companyTeamLabel = __('filament/emails/composer.fields.company_team_count');
@endphp

<div
    wire:key="mass-recipient-search-{{ implode('-', $selectedPersonIds) }}"
    x-data="{
        query: '',
        activeIndex: 0,
        options: @js(array_values($options)),
        selectedIds: @js($selectedPersonIds),
        companyTeamLabel: @js($companyTeamLabel),

        get matches() {
            const q = this.query.trim().toLowerCase();

            if (q === '') {
                return [];
            }

            return this.options
                .filter((option) => this.optionVisible(option))
                .filter((option) => [option.label, option.description, option.email]
                    .filter(Boolean)
                    .some((value) => value.toLowerCase().includes(q)))
                .slice(0, 8);
        },

        optionVisible(option) {
            if (option.type === 'person') {
                return ! this.selectedIds.includes(option.id);
            }

            return true;
        },

        async choose(option) {
            if (option.type === 'company_team') {
                await $wire.addMassCompanyTeamRecipients(option.id);
            } else {
                await $wire.addMassRecipient(option.id);
            }

            this.selectedIds = $wire.massRecipients.map((recipient) => recipient.personId);
            this.query = '';
            this.activeIndex = 0;
        },

        optionTooltip(option) {
            if (option.type === 'company_team') {
                return option.label + ' (' + this.companyTeamLabel + ' ' + option.count + ')';
            }

            if (option.email && option.label !== option.email) {
                return option.label + ' · ' + option.email;
            }

            return option.label || option.email || '';
        },
    }"
    {{ $attributes->class(['relative']) }}
>
    <label class="sr-only">{{ __('filament/emails/composer.mass_send.add_recipients') }}</label>

    <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 transition focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500 dark:border-white/10 dark:bg-gray-900 dark:focus-within:border-primary-500">
        <x-heroicon-o-plus class="h-4 w-4 shrink-0 text-gray-400" />
        <input
            type="text"
            x-model="query"
            placeholder="{{ __('filament/emails/composer.mass_send.add_recipients') }}"
            class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0 dark:text-gray-100"
            x-on:keydown.arrow-down.prevent="activeIndex = Math.min(activeIndex + 1, matches.length - 1)"
            x-on:keydown.arrow-up.prevent="activeIndex = Math.max(activeIndex - 1, 0)"
            x-on:keydown.enter.prevent="matches[activeIndex] && choose(matches[activeIndex])"
        />
    </div>

    <ul
        x-show="matches.length > 0"
        x-cloak
        class="absolute left-0 right-0 top-full z-20 mt-1 max-h-56 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-gray-900"
    >
        <template x-for="(option, index) in matches" x-bind:key="option.type + ':' + option.id">
            <li>
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center gap-2.5 px-2.5 py-2 text-left text-sm transition hover:bg-gray-50 dark:hover:bg-white/5"
                    x-bind:class="index === activeIndex ? 'bg-gray-50 dark:bg-white/5' : ''"
                    x-on:click="choose(option)"
                >
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300" aria-hidden="true">
                        <x-heroicon-o-user x-show="option.type === 'person'" class="h-4 w-4" />
                        <x-heroicon-o-building-office-2 x-show="option.type === 'company_team'" class="h-4 w-4" />
                    </span>
                    <span
                        class="min-w-0 flex-1"
                        x-bind:x-tooltip="optionTooltip(option) ? { content: optionTooltip(option), theme: $store.theme } : false"
                    >
                        <span class="block truncate font-medium text-gray-900 dark:text-gray-100" x-text="option.label"></span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="option.description"></span>
                    </span>
                    <span
                        x-show="option.type === 'company_team'"
                        class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300"
                        x-text="companyTeamLabel + ' (' + option.count + ')'"
                    ></span>
                </button>
            </li>
        </template>
    </ul>
</div>
