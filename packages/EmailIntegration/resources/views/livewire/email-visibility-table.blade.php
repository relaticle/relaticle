<div class="space-y-3">
    <div class="ei-visibility-search">
        <x-filament-tables::search-field
            :placeholder="__('filament/pages/email-privacy-settings.visibility.search_placeholder')"
            wire-model="search"
        />
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                        {{ __('filament/pages/email-privacy-settings.visibility.table.address') }}
                    </th>
                    <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                        {{ __('filament/pages/email-privacy-settings.visibility.table.enforcement') }}
                    </th>
                    <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                        {{ __('filament/pages/email-privacy-settings.visibility.table.updated') }}
                    </th>
                    <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                        {{ __('filament/pages/email-privacy-settings.visibility.table.added_by') }}
                    </th>
                    <th class="px-4 py-3 text-end font-medium text-gray-950 dark:text-white">
                        <span class="sr-only">{{ __('filament/pages/email-privacy-settings.visibility.table.actions') }}</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($rows as $row)
                    <tr
                        wire:key="visibility-row-{{ $row['key'] }}"
                        @class([
                            'bg-gray-50/70 dark:bg-white/[0.02]' => $row['is_system'],
                        ])
                    >
                        <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                            {{ $row['address'] }}
                        </td>
                        <td class="relative px-4 py-3">
                            <x-email-integration::enforcement-level-picker
                                :value="$row['enforcement_value']"
                                :entry-id="$row['entry_id'] ?? null"
                                :disabled="$row['is_system']"
                            />
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                            {{ $row['updated_at'] ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                            {{ $row['source'] }}
                        </td>
                        <td class="px-4 py-3 text-end">
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

    @if (! $this->hasCustomVisibilityEntries())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('filament/pages/email-privacy-settings.visibility.empty_hint') }}
        </p>
    @endif

    <x-filament-actions::modals />
</div>
