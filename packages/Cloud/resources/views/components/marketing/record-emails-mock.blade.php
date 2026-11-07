@php
    use App\Services\AvatarService;

    $avatars = resolve(AvatarService::class);
    $samAvatar = $avatars->generateAuto('Sam Patel');
    $mayaAvatar = $avatars->generateAuto('Maya Chen');

    $emails = [
        [$samAvatar, __('Re: Renewal quote for 2027'), 'Sam Patel, Lena Ortiz', __('Thanks Lena. The dates work, so I will send the contract on Thursday.'), 'Sam Patel', __('9:24 AM'), false],
        [null, __('Renewal quote for 2027'), 'Lena Ortiz, Sam Patel', __('Hi Sam, thanks for the call. The volume discount works for us.'), 'Sam Patel', __('Yesterday'), false],
        [$mayaAvatar, __('(subject hidden)'), 'Maya Chen, Lena Ortiz', null, 'Maya Chen', __('Yesterday'), true],
        [$mayaAvatar, __('Implementation timeline'), 'Maya Chen, Lena Ortiz', null, 'Maya Chen', __('Mon'), true],
    ];

    $accepted = [__('Accepted'), 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/20'];
    $maybe = [__('Maybe'), 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/20'];
    $signatureDay = today()->next('Thursday');
    $renewalCall = today()->previous('Tuesday');

    $meetings = [
        [__('Implementation kickoff'), $signatureDay->addDays(4)->setTime(14, 30), __('45 min'), 'Lena Ortiz, Maya Chen, Sam Patel', ...$maybe],
        [__('Contract signature'), $signatureDay->setTime(10, 0), __('30 min'), 'Lena Ortiz, Sam Patel', ...$accepted],
        [__('Renewal call'), $renewalCall->setTime(16, 0), __('30 min'), 'Lena Ortiz, Sam Patel', ...$accepted],
        [__('Pricing review'), $renewalCall->subDays(13)->setTime(11, 0), __('45 min'), 'Lena Ortiz, Sam Patel', ...$accepted],
    ];

    $tabId = 'record-tabs-'.\Illuminate\Support\Str::random(6);
@endphp

<x-cloud::marketing.window
    x-data="{
        tab: 'emails',
        switchTab() {
            this.tab = this.tab === 'emails' ? 'meetings' : 'emails'
            this.$nextTick(() => this.$refs[this.tab].focus())
        },
    }"
    {{ $attributes }}
>
    <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-white/[0.06]">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10" aria-hidden="true">
            <x-lucide-user class="size-5"/>
        </span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">Lena Ortiz</p>
            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ __('Head of Operations at Northwind') }}</p>
        </div>
        <span class="hidden items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-700 sm:inline-flex dark:border-white/[0.1] dark:text-gray-300" aria-hidden="true">
            <x-lucide-mail class="h-3.5 w-3.5"/>
            {{ __('Compose email') }}
        </span>
    </div>

    <div class="flex gap-5 overflow-hidden border-b border-gray-100 px-4 text-xs font-medium text-gray-500 sm:px-5 dark:border-white/[0.06] dark:text-gray-400">
        <span class="py-2.5" aria-hidden="true">{{ __('Tasks') }}</span>
        <span class="py-2.5" aria-hidden="true">{{ __('Notes') }}</span>
        <div role="tablist" aria-label="{{ __('Lena Ortiz activity') }}" class="flex gap-5">
            @foreach(['emails' => [__('Emails'), count($emails)], 'meetings' => [__('Meetings'), count($meetings)]] as $key => [$tabLabel, $count])
                <button
                    type="button"
                    role="tab"
                    id="{{ $tabId }}-{{ $key }}"
                    aria-controls="{{ $tabId }}-{{ $key }}-panel"
                    aria-selected="{{ $key === 'emails' ? 'true' : 'false' }}"
                    tabindex="{{ $key === 'emails' ? '0' : '-1' }}"
                    x-bind:aria-selected="(tab === '{{ $key }}').toString()"
                    x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
                    x-ref="{{ $key }}"
                    x-on:click="tab = '{{ $key }}'"
                    x-on:keydown.left.prevent="switchTab()"
                    x-on:keydown.right.prevent="switchTab()"
                    class="group -mb-px cursor-pointer border-b-2 border-transparent py-2.5 transition-colors hover:text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary aria-selected:border-primary aria-selected:text-primary dark:hover:text-gray-200 dark:aria-selected:border-primary-400 dark:aria-selected:text-primary-400"
                >
                    {{ $tabLabel }}
                    <span class="ml-0.5 rounded bg-gray-100 px-1 text-micro text-gray-600 group-aria-selected:bg-primary-50 group-aria-selected:text-primary dark:bg-white/[0.06] dark:text-gray-300 dark:group-aria-selected:bg-primary-500/15 dark:group-aria-selected:text-primary-400">{{ $count }}</span>
                </button>
            @endforeach
        </div>
        <span class="hidden py-2.5 sm:inline" aria-hidden="true">{{ __('Activity log') }}</span>
    </div>

    <div class="grid">
        <ul
            id="{{ $tabId }}-emails-panel"
            role="tabpanel"
            aria-labelledby="{{ $tabId }}-emails"
            x-bind:class="{ 'invisible': tab !== 'emails' }"
            class="col-start-1 row-start-1 min-w-0 divide-y divide-gray-100 dark:divide-white/[0.06]"
        >
            @foreach($emails as [$senderAvatar, $subject, $participants, $preview, $mailbox, $time, $restricted])
                <li class="flex gap-3 px-4 py-3 sm:px-5" data-mail-row>
                    @if($senderAvatar)
                        <img src="{{ $senderAvatar }}" alt="" class="size-8 shrink-0 rounded-full">
                    @else
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10" aria-hidden="true">
                            <x-lucide-user class="size-4"/>
                        </span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <p @class(['min-w-0 flex-1 truncate text-sm font-medium', $subject === __('(subject hidden)') ? 'text-gray-500 dark:text-gray-400' : 'text-gray-900 dark:text-white'])>{{ $subject }}</p>
                            <span class="hidden shrink-0 items-center gap-1 text-micro text-gray-500 sm:inline-flex dark:text-gray-400">
                                <x-lucide-mail class="h-3 w-3 text-gray-400 dark:text-gray-500"/>
                                {{ __('via :name', ['name' => $mailbox]) }}
                            </span>
                            <span class="shrink-0 text-micro text-gray-500 dark:text-gray-400">{{ $time }}</span>
                        </div>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $participants }}</p>
                        @if($restricted)
                            <span class="mt-1.5 inline-flex items-center gap-1 rounded-md border border-gray-200 px-1.5 py-0.5 text-micro text-gray-600 dark:border-white/[0.1] dark:text-gray-400" data-mail-lock>
                                <x-lucide-lock class="h-3 w-3"/>
                                {{ __('Request access from :name', ['name' => \Illuminate\Support\Str::before($mailbox, ' ')]) }}
                            </span>
                        @else
                            <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $preview }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <ul
            id="{{ $tabId }}-meetings-panel"
            role="tabpanel"
            aria-labelledby="{{ $tabId }}-meetings"
            x-bind:class="{ 'invisible': tab !== 'meetings' }"
            class="invisible col-start-1 row-start-1 min-w-0 divide-y divide-gray-100 self-start dark:divide-white/[0.06]"
        >
            @foreach($meetings as [$meetingTitle, $startsAt, $duration, $attendees, $response, $responseClasses])
                <li class="flex gap-3 px-4 py-3 sm:px-5">
                    <span class="flex w-10 shrink-0 flex-col items-center self-start rounded-lg border border-gray-200 py-1 dark:border-white/[0.1]" aria-hidden="true">
                        <span class="text-pico font-medium uppercase text-gray-500 dark:text-gray-400">{{ $startsAt->format('M') }}</span>
                        <span class="text-base font-semibold leading-5 text-gray-950 dark:text-white">{{ $startsAt->format('j') }}</span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <p class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white">{{ $meetingTitle }}</p>
                            <span class="{{ $responseClasses }} shrink-0 rounded-md px-1.5 py-0.5 text-micro font-medium ring-1 ring-inset">{{ $response }}</span>
                        </div>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                            <time datetime="{{ $startsAt->toIso8601String() }}">{{ $startsAt->format('l, g:i a') }}</time>
                            <span aria-hidden="true">·</span>
                            {{ $duration }}
                        </p>
                        <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $attendees }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</x-cloud::marketing.window>
