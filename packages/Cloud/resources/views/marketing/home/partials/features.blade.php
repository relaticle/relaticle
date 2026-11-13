@php
    $assistantName = (string) config('chat.assistant_name');
    $quotes = collect(config('marketing.testimonials'))->keyBy(fn (array $t): string => $t['slot'] ?? '')->all();
    $documentationActive = \Laravel\Pennant\Feature::active(\App\Features\Documentation::class);
@endphp

<x-cloud::marketing.frame id="features">
    <x-cloud::marketing.frame-header :label="__('Features')" :lead="__('One CRM, three ways to work.')">
        {{ __('Click, ask :name, or let your agent do it.', ['name' => $assistantName]) }}
    </x-cloud::marketing.frame-header>

    <div class="bg-rail grid gap-px">
        <x-cloud::marketing.showcase
            data-feature-row
            :label="__('01 · In the app')"
            :lead="__('A pipeline you read at a glance.')"
            :facts="[__('Board or list, your pick'), __('Drag a card to the next stage'), __('Rename, recolor or reorder the stages')]"
            :link-label="__('Track a deal through the pipeline')"
            :link-href="$documentationActive ? route('help.show', ['category' => 'getting-started', 'slug' => 'track-a-deal-through-the-pipeline']) : null"
            :quote="$quotes['board'] ?? null"
        >
            {{ __('Company, amount and close date, in the stages you define.') }}

            <x-slot:visual>
                @include('cloud::marketing.home.partials.board-mock')
            </x-slot:visual>
        </x-cloud::marketing.showcase>

        <x-cloud::marketing.showcase
            data-feature-row
            :label="__('02 · :name', ['name' => $assistantName])"
            :lead="__('Ask in plain words.')"
            :facts="[__('Type @ to point it at any record'), __('Every create, update and delete waits for your approval'), __('Fix a field on the card before you approve')]"
            :link-label="__('Meet :name', ['name' => $assistantName])"
            :link-href="route('ai')"
            :quote="$quotes['rela'] ?? null"
        >
            {{ __(':name drafts the change. You approve it before it is saved.', ['name' => $assistantName]) }}

            <x-slot:visual>
                <div class="flex min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
                    @include('cloud::marketing.home.partials.rela-mock')
                </div>
            </x-slot:visual>
        </x-cloud::marketing.showcase>

        <x-cloud::marketing.showcase
            data-feature-row
            :label="__('03 · Your agents')"
            :lead="__('Work from Claude or ChatGPT.')"
            :facts="[__('One consent screen, no API keys'), __('Acts as you, in the one workspace you approved'), __('Any MCP client with a personal access token')]"
            :link-label="__('Relaticle for Claude')"
            :link-href="route(\Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::Claude->routeName())"
            :quote="$quotes['agents'] ?? null"
        >
            {{ __('It reads and updates the same records you do, with your permissions.') }}

            <x-slot:visual>
                <div class="flex min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
                    @include('cloud::marketing.home.partials.agent-mock')
                </div>
            </x-slot:visual>
        </x-cloud::marketing.showcase>
    </div>
</x-cloud::marketing.frame>
