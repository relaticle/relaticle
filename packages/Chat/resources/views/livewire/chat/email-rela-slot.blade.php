<section class="border-b border-gray-200 px-4 py-3 sm:px-6 dark:border-white/10" @if ($summaryState === 'working' || $reviewing) wire:poll.2s="check" @endif
    x-data="{
        expanded: $persist(true).as('email.rela.expanded'),
        text: '', channel: null,
        init() {
            if ($wire.conversationId && window.Echo) {
                this.channel = 'chat.conversation.' + $wire.conversationId
                window.Echo.private(this.channel)
                    .listen('.stream_start', () => this.text = '')
                    .listen('.text_delta', event => this.text += event.delta || '')
                    .listen('.stream_end', () => $wire.check())
            }
        },
        destroy() { if (this.channel) window.Echo?.leave(this.channel) },
    }"
>
    <div class="flex items-center justify-between gap-3">
        <button type="button" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded" class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
            <x-lucide-sparkles class="size-4 text-gray-400" />
            {{ __('filament/emails/composer.rela.reader_title') }}
            <x-lucide-chevron-down class="size-4" x-bind:class="expanded && 'rotate-180'" />
        </button>
        @if ($available)
            <x-filament::button color="gray" size="xs" wire:click="summarize" :disabled="$summaryState === 'working'">{{ __('filament/emails/composer.rela.summarize') }}</x-filament::button>
        @endif
    </div>
    <div x-show="expanded" class="mt-3 space-y-3">
        <dl class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
            @foreach (['last_from_them', 'last_from_us'] as $field)
                <div>
                    <dt>{{ __('filament/emails/composer.rela.'.$field) }}</dt>
                    <dd class="mt-1 text-gray-700 dark:text-gray-200">{{ $facts[$field] === null ? __('filament/emails/composer.rela.none') : \Illuminate\Support\Facades\Date::parse($facts[$field])->timezone(auth()->user()->effectiveTimezone())->diffForHumans() }}</dd>
                </div>
            @endforeach
            @if ($facts['waiting_days'] > 0)
                <div><dt>{{ __('filament/emails/composer.rela.waiting') }}</dt><dd class="mt-1 text-gray-700 dark:text-gray-200">{{ trans_choice('filament/emails/composer.rela.business_days', $facts['waiting_days'], ['count' => $facts['waiting_days']]) }}</dd></div>
            @endif
        </dl>
        @if ($facts['opportunity'] !== null)
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $facts['opportunity']['name'] }} · {{ $facts['opportunity']['stage'] }}</p>
        @endif
        @if ($facts['next_meeting'] !== null)
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('filament/emails/composer.rela.next_meeting') }}: {{ $facts['next_meeting']['title'] }} · {{ \Illuminate\Support\Facades\Date::parse($facts['next_meeting']['starts_at'])->timezone(auth()->user()->effectiveTimezone())->diffForHumans() }}</p>
        @endif
        @if ($this->summary !== null)
            <div class="space-y-1">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('filament/emails/composer.rela.summary_by') }}</p>
                <p class="text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $this->summary->summary }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('filament/emails/composer.rela.summary_source', $facts['count'], ['count' => $facts['count']]) }}</p>
            </div>
        @elseif ($summaryState !== 'idle' && $summaryState !== 'done')
            <p role="status" class="text-xs text-gray-500 dark:text-gray-400">{{ __('filament/emails/composer.rela.summary_'.$summaryState) }}</p>
        @endif
        @if ($available)
            <x-filament::button size="xs" color="gray" wire:click="suggest" :disabled="$reviewing">{{ __('filament/emails/composer.rela.suggest') }}</x-filament::button>
        @endif
        @if ($reviewing)
            <p role="status" class="text-xs text-gray-500 dark:text-gray-400">{{ __('filament/emails/composer.rela.reviewing') }}</p>
            <p x-text="text" class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200"></p>
        @elseif (filled($lastReply))
            <div class="prose prose-sm max-w-none dark:prose-invert">{!! \Illuminate\Support\Str::markdown($lastReply, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        @endif
        @if (! $reviewing)
            @foreach ($plans as $plan)
                <livewire:chat.proposal-card :initial-pending-action-id="$plan->getKey()" context="email-reader" :key="'email-plan-'.$plan->getKey()" />
            @endforeach
        @endif
    </div>
</section>
