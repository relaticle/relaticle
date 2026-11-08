@php
    use App\Services\AvatarService;
    use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

    $lenaAvatar = resolve(AvatarService::class)->generateAuto('Lena Ortiz');
    $teammateView = EmailPrivacyTier::METADATA_ONLY;

    $emails = [
        [__('Re: Renewal quote for 2027'), __('The dates work for us. Send the contract on Thursday.'), __('9:24 AM')],
        [__('Renewal quote for 2027'), __('Hi Sam, thanks for the call. The volume discount works for us.'), __('Yesterday')],
        [__('Implementation timeline'), __('Sharing the rollout plan we discussed. Week one is onboarding.'), __('Mon')],
    ];

    $captions = [
        'you' => __('You always see your own email in full.'),
        'teammate' => __('Teammates see who you talked to and when. You choose how much more.'),
    ];

    $fade = 'transition duration-300 ease-out motion-reduce:transition-none';
    $stack = 'col-start-1 row-start-1';
    $choice = 'inline-flex h-9 cursor-pointer items-center rounded-full border border-white/60 bg-white/60 px-4 text-sm font-medium text-gray-700 backdrop-blur-sm transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary aria-checked:border-gray-950 aria-checked:bg-gray-950 aria-checked:text-white dark:border-white/10 dark:bg-white/[0.06] dark:text-gray-300 dark:hover:bg-white/10 dark:aria-checked:border-white dark:aria-checked:bg-white dark:aria-checked:text-gray-950';
@endphp

<div
    class="feat-card flex flex-col items-center gap-5 px-3 py-8 sm:px-8 md:py-12"
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

    <div
        class="flex flex-wrap justify-center gap-2"
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

    <x-cloud::marketing.window class="w-full max-w-2xl">
        <div class="grid border-b border-gray-100 dark:border-white/[0.06]">
            <div class="{{ $stack }} flex items-center gap-3 px-4 py-3.5 sm:px-5" x-bind:class="{ 'invisible': added }" data-mail-placeholder>
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full border border-dashed border-gray-300 text-gray-400 dark:border-white/15" aria-hidden="true">
                    <x-lucide-user class="size-5"/>
                </span>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Lena Ortiz is not in your CRM yet.') }}</p>
            </div>

            <div class="{{ $stack }} invisible flex items-center gap-3 px-4 py-3.5 opacity-0 sm:px-5" x-bind:class="{ 'invisible opacity-0': ! added, '{{ $fade }}': added }" data-mail-landing data-mail-person>
                <img src="{{ $lenaAvatar }}" alt="" class="size-10 shrink-0 rounded-full">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">Lena Ortiz</p>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ __('Head of Operations at Northwind') }}</p>
                </div>
                <span class="hidden shrink-0 items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-micro font-medium text-emerald-700 ring-1 ring-emerald-600/20 ring-inset sm:inline-flex dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/20">
                    <x-lucide-sparkle class="size-3" aria-hidden="true"/>
                    {{ __('Added from your inbox') }}
                </span>
            </div>
        </div>

        <div class="flex gap-5 overflow-hidden border-b border-gray-100 px-4 text-xs font-medium text-gray-500 sm:px-5 dark:border-white/[0.06] dark:text-gray-400" aria-hidden="true">
            <span class="hidden py-2.5 sm:inline">{{ __('Overview') }}</span>
            <span class="hidden py-2.5 sm:inline">{{ __('Activity') }}</span>
            <span class="py-2.5">{{ __('Tasks') }}</span>
            <span class="py-2.5">{{ __('Notes') }}</span>
            <span class="-mb-px border-b-2 border-primary py-2.5 text-primary dark:border-primary-400 dark:text-primary-400">
                {{ __('Emails') }}
                <span class="ml-0.5 rounded bg-primary-50 px-1 text-micro tabular-nums text-primary dark:bg-primary-500/15 dark:text-primary-400" x-text="landed" data-mail-count>0</span>
            </span>
            <span class="py-2.5">{{ __('Meetings') }}</span>
        </div>

        <ul class="divide-y divide-gray-100 dark:divide-white/[0.06]">
            @foreach($emails as $index => [$subject, $preview, $time])
                <li class="relative invisible flex gap-3 px-4 py-3 opacity-0 sm:px-5" x-bind:class="{ 'invisible opacity-0': landed <= {{ $index }}, '{{ $fade }}': landed > {{ $index }} }" data-mail-landing data-mail-row>
                    <span class="pointer-events-none absolute inset-0 bg-primary-500/10 opacity-0 transition-opacity duration-700 ease-out motion-reduce:transition-none dark:bg-primary-400/15" x-bind:class="{ 'opacity-0': flash !== {{ $index }} }" aria-hidden="true"></span>
                    <img src="{{ $lenaAvatar }}" alt="" class="relative size-8 shrink-0 rounded-full">
                    <div class="relative min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <p class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white" x-bind:class="{ 'text-gray-900 dark:text-white': showsSubject, 'text-gray-500 dark:text-gray-400': ! showsSubject }" x-text="showsSubject ? @js($subject) : @js(__('(subject hidden)'))" data-mail-subject>{{ $subject }}</p>
                            <span class="hidden shrink-0 items-center gap-1 text-micro text-gray-500 sm:inline-flex dark:text-gray-400">
                                <x-lucide-mail class="size-3 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                                {{ __('via :name', ['name' => 'Sam Patel']) }}
                            </span>
                            <span class="shrink-0 text-micro text-gray-500 dark:text-gray-400">{{ $time }}</span>
                        </div>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">Lena Ortiz, Sam Patel</p>
                        <div class="mt-0.5 grid">
                            <p class="{{ $stack }} truncate text-xs text-gray-500 dark:text-gray-400" x-bind:class="{ 'invisible': ! showsBody }" data-mail-preview>{{ $preview }}</p>
                            <span class="{{ $stack }} invisible inline-flex w-fit items-center gap-1 rounded-md border border-gray-200 px-1.5 py-0.5 text-micro text-gray-600 dark:border-white/[0.1] dark:text-gray-400" x-bind:class="{ 'invisible': showsBody }" data-mail-lock>
                                <x-lucide-lock class="size-3" aria-hidden="true"/>
                                {{ __('Request access from :name', ['name' => 'Sam']) }}
                            </span>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-cloud::marketing.window>

    <div class="grid max-w-md text-center text-sm text-gray-600 dark:text-gray-400" aria-live="polite">
        <p class="{{ $stack }}" x-text="seer === 'you' ? @js($captions['you']) : @js($captions['teammate'])" data-mail-caption>{{ $captions['you'] }}</p>
        @foreach($captions as $caption)
            <p class="{{ $stack }} invisible" aria-hidden="true">{{ $caption }}</p>
        @endforeach
    </div>
</div>
