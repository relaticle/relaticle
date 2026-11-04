<x-filament-widgets::widget>
    <x-filament::section :heading="__('cloud::admin.acquisition.heading')" :description="__('cloud::admin.acquisition.description')">
        @if($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::admin.acquisition.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('cloud::admin.acquisition.heading') }}</caption>
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            @foreach(['source', 'campaign', 'landing', 'signups', 'workspaces', 'setup', 'first_value', 'trial', 'checkout', 'paid', 'renewal_failed'] as $column)
                                <th scope="col" class="px-3 py-2 whitespace-nowrap">{{ __('cloud::admin.acquisition.columns.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <th scope="row" class="px-3 py-2 text-left font-medium">{{ $row['source'] === 'unknown' ? __('cloud::admin.acquisition.unknown') : $row['source'] }}</th>
                                <td class="px-3 py-2">{{ $row['campaign'] ?? __('cloud::admin.acquisition.unset') }}</td>
                                <td class="px-3 py-2">{{ $row['landing'] }}</td>
                                @foreach(['signups', 'workspaces', 'setup'] as $column)
                                    <td class="px-3 py-2 text-center">{{ $row[$column] }}</td>
                                @endforeach
                                <td class="px-3 py-2 text-center whitespace-nowrap">{{ $row['eligible'] > 0 ? $row['first_value'].' / '.$row['eligible'] : __('cloud::admin.acquisition.pending') }}</td>
                                @foreach(['trial', 'checkout', 'paid', 'renewal_failed'] as $column)
                                    <td class="px-3 py-2 text-center">{{ $row[$column] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::admin.acquisition.funnel_help') }}</p>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="mb-3 text-left font-medium">{{ __('cloud::admin.acquisition.adoption') }}</caption>
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            @foreach(['source', 'campaign', 'landing', 'chat', ...($emailEnabled ? ['mailbox', 'healthy_sync'] : []), 'import', 'partial_import', 'mcp'] as $column)
                                <th scope="col" class="px-3 py-2 whitespace-nowrap">{{ __('cloud::admin.acquisition.columns.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <th scope="row" class="px-3 py-2 text-left font-medium">{{ $row['source'] === 'unknown' ? __('cloud::admin.acquisition.unknown') : $row['source'] }}</th>
                                <td class="px-3 py-2">{{ $row['campaign'] ?? __('cloud::admin.acquisition.unset') }}</td>
                                <td class="px-3 py-2">{{ $row['landing'] }}</td>
                                @foreach(['chat', ...($emailEnabled ? ['mailbox', 'healthy_sync'] : []), 'import', 'partial_import', 'mcp'] as $column)
                                    <td class="px-3 py-2 text-center">{{ $row[$column] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::admin.acquisition.adoption_help') }}</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
