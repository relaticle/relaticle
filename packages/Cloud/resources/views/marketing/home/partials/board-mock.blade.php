@php
    use App\Enums\CustomFields\OpportunityField;

    $stageColors = OpportunityField::STAGE->optionKeyColors();
    $stage = fn (string $option): array => [
        'label' => __("enums/custom-fields.opportunity.stage.options.{$option}"),
        'color' => $stageColors["enums/custom-fields.opportunity.stage.options.{$option}"],
    ];
    $card = fn (string $title, string $company, string $logo, int $amount, int $days, array $attributes = []): array => [
        'title' => $title,
        'company' => $company,
        'logo' => $logo,
        'amount' => $amount,
        'date' => today()->addDays($days),
        'attributes' => $attributes,
    ];

    $columns = [
        $stage('prospecting') + ['count' => [2, 1], 'cards' => [
            $card(__('Notion API Integration'), 'Notion', 'ri-notion-fill', 18000, 12),
            $card(__('Figma Design System Rollout'), 'Figma', 'ri-figma-fill', 12500, 24, ['data-board-card' => true]),
        ]],
        $stage('qualification') + ['count' => [1, 2], 'slot' => true, 'cards' => [
            $card(__('Dropbox SSO Upgrade'), 'Dropbox', 'ri-dropbox-fill', 9800, 19),
        ]],
        $stage('needs_analysis') + ['cards' => [
            $card(__('Apple Developer Partnership'), 'Apple', 'ri-apple-fill', 25000, 22),
            $card(__('Vercel Partner Program'), 'Vercel', 'ri-vercel-fill', 30000, 36),
        ]],
        $stage('value_proposition') + ['cards' => [
            $card(__('GitHub Enterprise Migration'), 'GitHub', 'ri-github-fill', 48000, 41),
            $card(__('Spotify Ads Pilot'), 'Spotify', 'ri-spotify-fill', 24000, 31),
        ]],
    ];
@endphp

<div class="absolute inset-x-3 bottom-0 top-7 overflow-hidden md:inset-x-7">
    <x-cloud::marketing.app-board class="h-full" :columns="$columns" data-board/>
</div>

<div class="pointer-events-none absolute inset-y-0 right-0 hidden w-28 bg-linear-to-r from-transparent to-tint md:block" aria-hidden="true"></div>
