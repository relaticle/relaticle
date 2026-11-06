@php
    use Relaticle\Cloud\Marketing\Enums\ConnectableAssistant;

    $clients = [
        ConnectableAssistant::Claude->value => [ConnectableAssistant::Claude, 'text-anthropic'],
        ConnectableAssistant::ChatGPT->value => [ConnectableAssistant::ChatGPT, 'text-gray-900 dark:text-white'],
    ];
@endphp

<div class="relative w-full max-w-md" data-agent-demo x-data="{ client: 'claude' }">
    <x-cloud::marketing.window>
        <div class="flex items-center gap-2 border-b border-gray-100 px-3 py-2.5 dark:border-white/[0.06]">
            <div role="tablist" aria-label="{{ __('AI assistant') }}" class="flex gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.06]">
                @foreach($clients as $key => [$client, $iconColor])
                    <button
                        type="button"
                        role="tab"
                        id="agent-tab-{{ $key }}"
                        aria-controls="agent-panel"
                        aria-selected="{{ $key === 'claude' ? 'true' : 'false' }}"
                        tabindex="{{ $key === 'claude' ? '0' : '-1' }}"
                        x-ref="{{ $key }}"
                        x-bind:aria-selected="(client === '{{ $key }}').toString()"
                        x-bind:tabindex="client === '{{ $key }}' ? 0 : -1"
                        x-on:click="client = '{{ $key }}'"
                        x-on:keydown.left.prevent="client = client === 'claude' ? 'chatgpt' : 'claude'; $nextTick(() => $refs[client].focus())"
                        x-on:keydown.right.prevent="client = client === 'claude' ? 'chatgpt' : 'claude'; $nextTick(() => $refs[client].focus())"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-2.5 py-1 text-sm font-medium text-gray-500 transition hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary-500 aria-selected:bg-white aria-selected:text-gray-900 aria-selected:shadow-sm dark:text-gray-400 dark:hover:text-white dark:aria-selected:bg-white/10 dark:aria-selected:text-white"
                    >
                        <x-dynamic-component :component="$client->icon()" class="size-4 {{ $iconColor }}"/>
                        {{ $client->label() }}
                    </button>
                @endforeach
            </div>
            <span class="ml-auto inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                <span class="size-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                {{ __('Relaticle connected') }}
            </span>
        </div>

        <div id="agent-panel" role="tabpanel" x-bind:aria-labelledby="`agent-tab-${client}`" aria-labelledby="agent-tab-claude" class="space-y-3 px-4 pb-10 pt-4">
            <div class="flex justify-end">
                <p class="max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-3.5 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
                    {{ __('Add a follow-up with Lena at Northwind for Thursday') }}
                </p>
            </div>
            <p class="inline-flex items-center gap-1.5 rounded-md border border-gray-200/80 bg-gray-50 px-2 py-1 text-xs text-gray-600 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-gray-300" data-agent-step>
                <x-ri-plug-line class="size-3.5 shrink-0 text-primary dark:text-primary-400"/>
                {{ __('Relaticle') }}
                <span aria-hidden="true">·</span>
                {{ __('Create task') }}
                <x-ri-check-line class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"/>
            </p>
            <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300" data-agent-step>
                {{ __('Done. The task is due Thursday and linked to Lena Ortiz and the Northwind renewal.') }}
            </p>
        </div>
    </x-cloud::marketing.window>

    <x-cloud::marketing.window class="relative z-10 -mt-6 ml-auto w-[92%]" data-agent-task>
        <div class="flex items-center gap-1.5 border-b border-gray-100 py-2 pl-4 pr-3 text-xs text-gray-500 dark:border-white/[0.06] dark:text-gray-400">
            <x-brand.logomark class="h-3.5!"/>
            <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Relaticle') }}</span>
            <span aria-hidden="true">·</span>
            {{ __('Tasks') }}
            <span class="ml-auto">{{ __('Just now') }}</span>
            <span class="grid shrink-0 justify-items-end" data-agent-actor>
                <x-cloud::marketing.actor as="claude" class="col-start-1 row-start-1" x-bind:class="{ 'invisible': client !== 'claude' }"/>
                <x-cloud::marketing.actor as="chatgpt" class="invisible col-start-1 row-start-1" x-bind:class="{ 'invisible': client !== 'chatgpt' }"/>
            </span>
        </div>
        <div class="flex items-center gap-3 px-4 py-3">
            <span class="size-4 shrink-0 rounded-full border-2 border-gray-300 dark:border-white/20" aria-hidden="true"></span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ __('Follow up with Lena Ortiz') }}</p>
                <div class="mt-1.5 flex min-w-0 items-center gap-1.5 overflow-hidden text-xs">
                    <span class="inline-flex shrink-0 items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                        <x-ri-calendar-line class="size-3"/>{{ __('Thursday') }}
                    </span>
                    <span class="shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">Lena Ortiz</span>
                    <span class="min-w-0 truncate rounded-md bg-gray-100 px-1.5 py-0.5 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">{{ __('Northwind renewal') }}</span>
                </div>
            </div>
        </div>
    </x-cloud::marketing.window>
</div>
