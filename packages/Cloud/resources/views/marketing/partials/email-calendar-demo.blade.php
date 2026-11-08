@php
    use App\Enums\CrmEntity;
    use App\Services\AvatarService;
    use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

    $avatars = resolve(AvatarService::class);
    $lenaAvatar = $avatars->generateAuto('Lena Ortiz');
    $samAvatar = $avatars->generateAuto('Sam Patel');
    $teammateView = EmailPrivacyTier::METADATA_ONLY;

    $emails = [
        [$lenaAvatar, __('Re: Renewal quote for 2027'), 'Lena Ortiz, Sam Patel', __('The dates work for us. Send the contract on Thursday.'), true, __('9:24 AM'), 'Sales'],
        [$samAvatar, __('Renewal quote for 2027'), 'Sam Patel, Lena Ortiz', __('Hi Lena, thanks for the call. The quote with the volume discount is attached.'), false, __('Yesterday'), 'Sales'],
        [$lenaAvatar, __('Implementation timeline'), 'Lena Ortiz, Sam Patel', __('Sharing the rollout plan we discussed. Week one is onboarding.'), false, __('Mon'), null],
    ];

    $tabs = [
        ['lucide-layout-grid', __('Overview'), 'hidden sm:flex'],
        ['lucide-history', __('Activity'), 'hidden md:flex'],
        [CrmEntity::Task->icon(), __('Tasks'), 'hidden sm:flex'],
        [CrmEntity::Note->icon(), __('Notes'), 'flex'],
    ];

    $captions = [
        'you' => __('You always see your own email in full.'),
        'teammate' => __('Teammates see who you talked to and when. You choose how much more.'),
    ];

    $fade = 'transition duration-300 ease-out motion-reduce:transition-none';
    $stack = 'col-start-1 row-start-1';
    $choice = 'inline-flex h-7 cursor-pointer items-center rounded-md px-3 text-xs font-medium text-gray-600 transition-colors hover:text-gray-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary aria-checked:bg-white aria-checked:text-gray-950 aria-checked:shadow-xs aria-checked:ring-1 aria-checked:ring-gray-950/10 dark:text-gray-400 dark:hover:text-white dark:aria-checked:bg-gray-800 dark:aria-checked:text-white dark:aria-checked:ring-white/10';
@endphp

<div
    class="flex flex-col gap-4"
    data-email-demo
    data-demo-state="idle"
    x-data="{
        landed: 0,
        total: @js(count($emails)),
        added: false,
        flash: null,
        played: false,
        seer: 'you',
        seers: ['you', 'teammate'],
        shows: @js(['subject' => $teammateView->showsSubject(), 'body' => $teammateView->showsBody()]),
        timers: [],
        init() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return this.finish()
            }

            new IntersectionObserver(([entry], observer) => {
                if (entry.isIntersecting) {
                    observer.disconnect()
                    this.play()
                }
            }, { threshold: 0.4 }).observe(this.$el)
        },
        play() {
            this.later(() => this.added = true, 400)

            for (let index = 0; index < this.total; index++) {
                this.later(() => this.land(index), 900 + index * 550)
            }

            this.later(() => this.finish(), 900 + this.total * 550 + 600)
        },
        later(callback, delay) {
            this.timers.push(setTimeout(callback, delay))
        },
        land(index) {
            this.landed = index + 1
            this.flash = index
        },
        finish() {
            this.timers.forEach(clearTimeout)
            this.timers = []
            this.added = true
            this.landed = this.total
            this.flash = null
            this.played = true
        },
        lookAs(seer) {
            this.finish()
            this.seer = seer
        },
        step(offset) {
            const next = this.seers[(this.seers.indexOf(this.seer) + offset + this.seers.length) % this.seers.length]

            this.lookAs(next)
            this.$nextTick(() => this.$refs[next].focus())
        },
        get showsSubject() {
            return this.seer === 'you' || this.shows.subject
        },
        get showsBody() {
            return this.seer === 'you' || this.shows.body
        },
    }"
    x-bind:data-demo-state="played ? 'done' : (added ? 'playing' : 'idle')"
>
    <noscript><style>[data-email-demo] [data-mail-landing] { visibility: visible !important; opacity: 1 !important; } [data-email-demo] [data-mail-placeholder] { display: none !important; }</style></noscript>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <div
            class="inline-flex w-fit shrink-0 gap-0.5 rounded-lg bg-gray-950/[0.04] p-0.5 ring-1 ring-gray-950/5 dark:bg-white/[0.06] dark:ring-white/10"
            role="radiogroup"
            aria-label="{{ __('See the record as') }}"
            x-on:keydown.left.prevent="step(-1)"
            x-on:keydown.up.prevent="step(-1)"
            x-on:keydown.right.prevent="step(1)"
            x-on:keydown.down.prevent="step(1)"
        >
            <button type="button" role="radio" class="{{ $choice }}" aria-checked="true" tabindex="0" x-bind:aria-checked="(seer === 'you').toString()" x-bind:tabindex="seer === 'you' ? 0 : -1" x-ref="you" x-on:click="lookAs('you')" data-mail-seer="you">{{ __('As you') }}</button>
            <button type="button" role="radio" class="{{ $choice }}" aria-checked="false" tabindex="-1" x-bind:aria-checked="(seer === 'teammate').toString()" x-bind:tabindex="seer === 'teammate' ? 0 : -1" x-ref="teammate" x-on:click="lookAs('teammate')" data-mail-seer="teammate">{{ __('As a teammate') }}</button>
        </div>

        <div class="grid items-center text-[13px] leading-5 text-gray-600 dark:text-gray-400" aria-live="polite">
            <p class="{{ $stack }}" x-text="seer === 'you' ? @js($captions['you']) : @js($captions['teammate'])" data-mail-caption>{{ $captions['you'] }}</p>
            @foreach($captions as $caption)
                <p class="{{ $stack }} invisible" aria-hidden="true">{{ $caption }}</p>
            @endforeach
        </div>
    </div>

    <x-cloud::marketing.window class="w-full">
        <div class="flex h-11 items-center justify-between gap-3 border-b border-gray-100 px-4 dark:border-white/[0.06]" aria-hidden="true">
            <div class="flex min-w-0 items-center gap-1.5 text-[13px]">
                <x-lucide-user class="size-3.5 shrink-0 text-gray-400 dark:text-gray-500"/>
                <span class="text-gray-500 dark:text-gray-400">{{ __('People') }}</span>
                <span class="invisible flex items-center gap-1.5 opacity-0" x-bind:class="{ 'invisible opacity-0': ! added, '{{ $fade }}': added }" data-mail-landing>
                    <span class="text-gray-300 dark:text-gray-600">/</span>
                    <span class="truncate font-medium text-gray-900 dark:text-white">Lena Ortiz</span>
                </span>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 dark:border-white/[0.1] dark:text-gray-300">
                <x-lucide-messages-square class="size-3.5"/>
                {{ __('Ask :name', ['name' => config('chat.assistant_name')]) }}
            </span>
        </div>

        <div class="grid">
            <div class="{{ $stack }} flex items-center gap-3 px-4 py-4" x-bind:class="{ 'invisible': added }" data-mail-placeholder>
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full border border-dashed border-gray-300 text-gray-400 dark:border-white/15 dark:text-gray-500" aria-hidden="true">
                    <x-lucide-user class="size-5"/>
                </span>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Lena Ortiz is not in your CRM yet.') }}</p>
            </div>

            <div class="{{ $stack }} invisible flex items-center gap-3 px-4 py-4 opacity-0" x-bind:class="{ 'invisible opacity-0': ! added, '{{ $fade }}': added }" data-mail-landing data-mail-person>
                <img src="{{ $lenaAvatar }}" alt="" class="size-10 shrink-0 rounded-full">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[15px] font-semibold text-gray-900 dark:text-white">Lena Ortiz</p>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ __('Head of Operations at Northwind') }}</p>
                </div>
                <span class="hidden shrink-0 items-center gap-1.5 text-micro text-gray-500 sm:inline-flex dark:text-gray-400">
                    {{ __('Added by') }}
                    <x-cloud::marketing.actor as="mailbox"/>
                </span>
            </div>
        </div>

        <div class="flex gap-4 overflow-hidden border-b border-gray-100 px-4 text-xs font-medium text-gray-500 dark:border-white/[0.06] dark:text-gray-400" aria-hidden="true">
            @foreach($tabs as [$icon, $label, $display])
                <span class="{{ $display }} items-center gap-1.5 py-2.5">
                    <x-dynamic-component :component="$icon" class="size-3.5 text-gray-400 dark:text-gray-500"/>
                    {{ $label }}
                </span>
            @endforeach
            <span class="-mb-px flex items-center gap-1.5 border-b-2 border-primary py-2.5 text-primary dark:border-primary-400 dark:text-primary-400">
                <x-lucide-mail class="size-3.5"/>
                {{ __('Emails') }}
                <span class="rounded bg-primary-50 px-1 text-micro tabular-nums text-primary dark:bg-primary-500/15 dark:text-primary-400" x-text="landed" data-mail-count>0</span>
            </span>
            <span class="flex items-center gap-1.5 py-2.5">
                <x-lucide-calendar class="size-3.5 text-gray-400 dark:text-gray-500"/>
                {{ __('Meetings') }}
            </span>
        </div>

        <ul class="divide-y divide-gray-100 dark:divide-white/[0.06]">
            @foreach($emails as $index => [$avatar, $subject, $participants, $preview, $isReply, $time, $category])
                <li class="relative invisible flex gap-3 px-4 py-3 opacity-0" x-bind:class="{ 'invisible opacity-0': landed <= {{ $index }}, '{{ $fade }}': landed > {{ $index }} }" data-mail-landing data-mail-row>
                    <span class="pointer-events-none absolute inset-0 bg-primary-500/[0.07] opacity-0 transition-opacity duration-700 ease-out motion-reduce:transition-none dark:bg-primary-400/10" x-bind:class="{ 'opacity-0': flash !== {{ $index }} }" aria-hidden="true"></span>
                    <img src="{{ $avatar }}" alt="" class="relative size-8 shrink-0 rounded-full">
                    <div class="relative min-w-0 flex-1">
                        <div class="flex items-center gap-3">
                            <p class="min-w-0 flex-1 truncate text-sm font-medium leading-5" x-bind:class="{ 'text-gray-800 dark:text-gray-200': showsSubject, 'text-gray-500 dark:text-gray-400': ! showsSubject }" x-text="showsSubject ? @js($subject) : @js(__('(subject hidden)'))" data-mail-subject>{{ $subject }}</p>
                            <span class="flex shrink-0 items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <span class="hidden items-center gap-1 sm:inline-flex">
                                    <x-lucide-mail class="size-3.5 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                                    {{ __('via :name', ['name' => 'Sam Patel']) }}
                                </span>
                                <span class="tabular-nums">{{ $time }}</span>
                            </span>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $participants }}</p>
                        <div class="mt-0.5 grid">
                            <div class="{{ $stack }} flex min-w-0 items-center justify-between gap-3" x-bind:class="{ 'invisible': ! showsBody }" data-mail-preview>
                                <span class="flex min-w-0 items-center gap-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                    <x-dynamic-component :component="$isReply ? 'lucide-reply' : 'lucide-file-text'" class="size-3.5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                                    <span class="truncate">{{ $preview }}</span>
                                </span>
                                @if($category !== null)
                                    <span class="hidden shrink-0 rounded-md bg-violet-100 px-2 py-0.5 text-[11px] font-medium leading-none text-violet-700 sm:inline-flex dark:bg-violet-900/40 dark:text-violet-300">{{ $category }}</span>
                                @endif
                            </div>
                            <span class="{{ $stack }} invisible inline-flex w-fit items-center gap-1 self-center rounded-md border border-gray-200 px-1.5 py-0.5 text-micro text-gray-600 dark:border-white/[0.1] dark:text-gray-400" x-bind:class="{ 'invisible': showsBody }" data-mail-lock>
                                <x-lucide-lock class="size-3" aria-hidden="true"/>
                                {{ __('Request access from :name', ['name' => 'Sam']) }}
                            </span>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-cloud::marketing.window>
</div>
