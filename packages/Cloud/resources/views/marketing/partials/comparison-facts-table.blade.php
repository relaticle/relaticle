@php
    $formatStars = function (array $facts): string {
        if ($facts['stars'] === 0) {
            return __('No public GitHub repository');
        }

        return __(':stars stars (as of :date)', [
            'stars' => number_format($facts['stars']),
            'date' => \Carbon\CarbonImmutable::parse($facts['stars_verified'])->format('M j, Y'),
        ]);
    };

    $formatContributors = function (array $facts): string {
        if (! is_int($facts['contributors'])) {
            return __('Not applicable: closed-source, no public repository');
        }

        return __(':count contributors (as of :date)', [
            'count' => number_format($facts['contributors']),
            'date' => \Carbon\CarbonImmutable::parse($facts['contributors_verified'])->format('M j, Y'),
        ]);
    };

    $rows = [
        [__('License'), $relaticle['license'], $competitor['license']],
        [__('Pricing model'), $relaticle['pricing'], $competitor['pricing']],
        [__('GitHub stars'), $formatStars($relaticle), $formatStars($competitor)],
        [__('Contributors'), $formatContributors($relaticle), $formatContributors($competitor)],
        [__('Tech stack'), $relaticle['stack'], $competitor['stack']],
        [__('AI & MCP capabilities'), $relaticle['ai'], $competitor['ai']],
        [__('Self-hosting & deployment'), $relaticle['self_host'], $competitor['self_host']],
        [__('Extensibility'), $relaticle['extensibility'], $competitor['extensibility']],
    ];
@endphp

<div class="bg-rail grid gap-px">
    @foreach ($rows as [$label, $relaticleValue, $competitorValue])
        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-12">
            <p class="bg-surface px-6 py-5 text-sm font-medium text-gray-950 md:col-span-3 md:px-10 dark:text-white">{{ $label }}</p>
            <p class="bg-surface px-6 py-5 md:col-span-5 md:px-8">
                <span class="type-label mb-1 block text-gray-500 dark:text-gray-400">{{ $relaticle['name'] }}:</span>
                <span class="type-prose text-gray-700 dark:text-gray-300">{{ $relaticleValue }}</span>
            </p>
            <p class="bg-surface px-6 py-5 md:col-span-4 md:px-8">
                <span class="type-label mb-1 block text-gray-500 dark:text-gray-400">{{ $competitor['name'] }}:</span>
                <span class="type-prose text-gray-700 dark:text-gray-300">{{ $competitorValue }}</span>
            </p>
        </div>
    @endforeach
</div>
