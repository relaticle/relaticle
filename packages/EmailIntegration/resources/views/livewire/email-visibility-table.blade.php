@php
    $rows = $this->visibilityRows;
    $customRows = collect($rows)->where('is_system', false);
    $enforcementOptions = \Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement::cases();
@endphp

<div class="ei-visibility-table space-y-3">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="relative w-full sm:max-w-xs">
            <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400 dark:text-gray-500">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
            </div>

            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('filament/pages/email-privacy-settings.visibility.search_placeholder') }}"
                class="fi-input block w-full rounded-lg border-none bg-white py-2 ps-9 pe-3 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:focus:ring-primary-500"
            />
        </div>

        <div class="flex shrink-0 justify-end">
            {{ $this->addVisibilityContactAction }}
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full table-auto text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament/pages/email-privacy-settings.visibility.table.address') }}
                    </th>
                    <th class="px-4 py-3 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament/pages/email-privacy-settings.visibility.table.enforcement') }}
                    </th>
                    <th class="px-4 py-3 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament/pages/email-privacy-settings.visibility.table.updated') }}
                    </th>
                    <th class="px-4 py-3 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament/pages/email-privacy-settings.visibility.table.added_by') }}
                    </th>
                    <th class="px-4 py-3 text-end text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <span class="sr-only">{{ __('filament/pages/email-privacy-settings.visibility.table.actions') }}</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($rows as $row)
                    <tr
                        wire:key="visibility-entry-{{ $row['key'] }}"
                        @class([
                            'bg-white dark:bg-transparent' => ! $row['is_system'],
                            'bg-gray-50/70 dark:bg-white/[0.02]' => $row['is_system'],
                        ])
                    >
                        <td class="px-4 py-3.5 font-medium text-gray-950 dark:text-white">
                            {{ $row['address'] }}
                        </td>
                        <td class="px-4 py-3.5">
                            @if ($row['is_system'])
                                <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                    {{ $row['enforcement'] }}
                                </span>
                            @else
                                <select
                                    wire:change="updateEnforcement('{{ $row['entry_id'] }}', $event.target.value)"
                                    class="fi-select-input block w-full max-w-[12rem] rounded-lg border-none bg-white py-1.5 pe-8 ps-3 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:focus:ring-primary-500"
                                    aria-label="{{ __('filament/pages/email-privacy-settings.visibility.table.enforcement') }}"
                                >
                                    @foreach ($enforcementOptions as $option)
                                        <option
                                            value="{{ $option->value }}"
                                            @selected($row['enforcement_value'] === $option->value)
                                            title="{{ $option->getDescription() }}"
                                        >
                                            {{ $option->getLabel() }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-gray-600 dark:text-gray-300">
                            {{ $row['updated_at'] ?? '—' }}
                        </td>
                        <td class="px-4 py-3.5 text-gray-600 dark:text-gray-300">
                            {{ $row['source'] }}
                        </td>
                        <td class="px-4 py-3.5 text-end">
                            @if (! $row['is_system'])
                                {{ ($this->deleteVisibilityEntryAction)(['entry_id' => $row['entry_id']]) }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ __('filament/pages/email-privacy-settings.visibility.empty_heading') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($customRows->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('filament/pages/email-privacy-settings.visibility.empty_hint') }}
        </p>
    @endif

    <x-filament-actions::modals />
</div>
