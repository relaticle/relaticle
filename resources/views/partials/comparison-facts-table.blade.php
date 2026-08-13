@php
    /**
     * List layout kept for responsive styling (a real <table> doesn't stack
     * cleanly on narrow viewports); tables DO convert to markdown since the
     * TableAwareLeagueDriver landed (see app/Support/Markdown) — this is a
     * presentation choice, not a markdown-conversion workaround. See the
     * same pattern in press.blade.php.
     */
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
            return __('Not applicable — closed-source, no public repository');
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

<ul class="divide-y divide-gray-100 rounded-2xl border border-gray-200/80 bg-white dark:divide-white/[0.04] dark:border-white/[0.06] dark:bg-white/[0.02]">
    @foreach ($rows as [$label, $relaticleValue, $competitorValue])
        <li class="px-4 py-4 sm:px-6">
            <p class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ $label }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 sm:gap-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-medium text-gray-500 dark:text-gray-500">{{ $relaticle['name'] }}:</span> {{ $relaticleValue }}
                </p>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-medium text-gray-500 dark:text-gray-500">{{ $competitor['name'] }}:</span> {{ $competitorValue }}
                </p>
            </div>
        </li>
    @endforeach
</ul>
