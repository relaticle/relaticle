<div class="space-y-3">
    @if ($this->blocklistEntries->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                            {{ __('filament/pages/email-privacy-settings.blocklist.table.address') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                            {{ __('filament/pages/email-privacy-settings.blocklist.table.type') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                            {{ __('filament/pages/email-privacy-settings.blocklist.table.added_by') }}
                        </th>
                        <th class="px-4 py-3 text-end font-medium text-gray-950 dark:text-white">
                            <span class="sr-only">{{ __('filament/pages/email-privacy-settings.blocklist.table.actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($this->blocklistEntries as $entry)
                        <tr wire:key="blocklist-entry-{{ $entry->getKey() }}">
                            <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                                {{ $entry->value }}
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                {{ $entry->type->getLabel() }}
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                {{ $entry->creator?->name ?? $entry->user?->name ?? __('filament/pages/email-privacy-settings.blocklist.table.unknown_user') }}
                            </td>
                            <td class="px-4 py-3 text-end">
                                {{ ($this->deleteBlocklistEntryAction)(['entry_id' => $entry->getKey()]) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center dark:border-white/20">
            <p class="text-sm font-medium text-gray-950 dark:text-white">
                {{ $emptyHeading }}
            </p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">
                {{ $emptyDescription }}
            </p>

            <div class="mt-4 flex justify-center">
                {{ $this->addBlocklistAction }}
            </div>
        </div>
    @endif
</div>
