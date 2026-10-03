<x-filament-widgets::widget>
    <x-filament::section :heading="$heading" description="Share of each week's real signups active (own data or typed chat) in their signup week and each of the next three weeks. A curve that stops falling is the goal.">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="py-2">Signed up (week of)</th>
                    <th class="py-2 text-center">Size</th>
                    <th class="py-2 text-center">Same week</th>
                    <th class="py-2 text-center">+1 week</th>
                    <th class="py-2 text-center">+2 weeks</th>
                    <th class="py-2 text-center">+3 weeks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-2">{{ $row['week']->format('M j') }}</td>
                        <td class="py-2 text-center">{{ $row['size'] }}</td>
                        @foreach ($row['shares'] as $share)
                            <td class="py-2 text-center {{ $share === null ? 'text-gray-300 dark:text-gray-600' : '' }}">{{ $share === null ? '·' : $share.'%' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-widgets::widget>
