@php
    $rows = resolve(\Relaticle\EmailIntegration\Services\WorkspaceEmailProtectionService::class)
        ->protectionTableRows($this->currentTeam(), $this->protectedRecipientEntries);
    $customRows = collect($rows)->where('is_system', false);
@endphp

<div class="space-y-3">
    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                        {{ __('filament/pages/email-privacy-settings.privacy_protections.table.address') }}
                    </th>
                    <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                        {{ __('filament/pages/email-privacy-settings.privacy_protections.table.protection') }}
                    </th>
                    <th class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white">
                        {{ __('filament/pages/email-privacy-settings.privacy_protections.table.source') }}
                    </th>
                    <th class="px-4 py-3 text-end font-medium text-gray-950 dark:text-white">
                        <span class="sr-only">{{ __('filament/pages/email-privacy-settings.privacy_protections.table.actions') }}</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($rows as $row)
                    <tr wire:key="protected-recipient-{{ $row['key'] }}">
                        <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                            {{ $row['address'] }}
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                            {{ $row['protection'] }}
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                            {{ $row['source'] }}
                        </td>
                        <td class="px-4 py-3 text-end">
                            @if (! $row['is_system'])
                                {{ ($this->deleteProtectedRecipientAction)(['entry_id' => $row['entry_id']]) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($customRows->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 px-6 py-8 text-center dark:border-white/20">
            <p class="text-sm font-medium text-gray-950 dark:text-white">
                {{ $emptyHeading }}
            </p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">
                {{ $emptyDescription }}
            </p>

            <div class="mt-4 flex justify-center">
                {{ $this->addProtectedRecipientAction }}
            </div>
        </div>
    @endif
</div>
