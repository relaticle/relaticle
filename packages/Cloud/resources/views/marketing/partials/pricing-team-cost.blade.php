@use(Illuminate\Support\Number)
@use(App\Enums\Plan)
@use(Relaticle\Cloud\Marketing\Support\CompetitorFacts)
@php
    $locale = str_replace('_', '-', app()->getLocale());
    $people = 5;
    $maxPeople = 50;
    $seatPrice = CompetitorFacts::medianSeatPrice();
    $pro = Plan::Pro->yearlyPricePerMonth();
    $scale = Plan::Scale->yearlyPricePerMonth();
    $seatTotal = $people * $seatPrice;
    $top = max($seatTotal, $scale);
    $money = fn (float $amount): string => Number::currency($amount, 'USD', $locale, precision: fmod($amount, 1.0) === 0.0 ? 0 : 2);
    $clip = fn (float $amount): string => 'clip-path: inset(0 '.round(100 - $amount / $top * 100, 2).'% 0 0 round 0.375rem)';

    $copy = [
        'more' => __('A per-seat CRM costs :amount more a year than :plan.', ['plan' => Plan::Pro->marketingLabel()]),
        'breakEven' => __('A per-seat CRM costs less at this size. :plan costs less from :count people.', ['plan' => Plan::Pro->marketingLabel()]),
        'less' => __('A per-seat CRM costs less at this size.'),
    ];

    $bars = [
        ['key' => 'seat', 'label' => __('Per-seat CRM'), 'fill' => 'bg-gray-400 dark:bg-gray-500'],
        ['key' => 'pro', 'label' => Plan::Pro->marketingLabel(), 'fill' => 'bg-primary dark:bg-primary-400'],
        ['key' => 'scale', 'label' => Plan::Scale->marketingLabel(), 'fill' => 'bg-primary dark:bg-primary-400'],
    ];
@endphp

<div
    id="team-cost"
    class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12"
    data-team-cost
    x-data="{
        people: @js($people),
        seat: @js($seatPrice),
        pro: @js($pro),
        scale: @js($scale),
        copy: @js($copy),
        get seatPrice() { return Math.min(Math.max(Number(this.seat) || 0, 0), 10000) },
        get seatTotal() { return this.people * this.seatPrice },
        get amounts() { return { seat: this.seatTotal, pro: this.pro, scale: this.scale } },
        get top() { return Math.max(this.seatTotal, this.scale) },
        clip(amount) { return `inset(0 ${100 - amount / this.top * 100}% 0 0 round 0.375rem)` },
        money(amount) {
            return new Intl.NumberFormat(@js($locale), { style: 'currency', currency: 'USD', minimumFractionDigits: amount % 1 === 0 ? 0 : 2, maximumFractionDigits: 2 }).format(amount)
        },
        get verdict() {
            if (this.seatTotal > this.pro) {
                return this.copy.more.replace(':amount', this.money(Math.round((this.seatTotal - this.pro) * 12)))
            }

            return this.seatPrice > 0
                ? this.copy.breakEven.replace(':count', Math.floor(this.pro / this.seatPrice) + 1)
                : this.copy.less
        },
    }"
>
    <div class="bg-surface flex flex-col gap-8 p-6 md:p-10 lg:col-span-5">
        <div>
            <div class="flex items-baseline justify-between gap-4">
                <label for="team-cost-people" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('People on your team') }}</label>
                <output for="team-cost-people" class="type-title text-gray-950 tabular-nums dark:text-white" x-text="people" data-team-cost-people>{{ $people }}</output>
            </div>
            <input
                id="team-cost-people"
                type="range"
                min="1"
                max="{{ $maxPeople }}"
                step="1"
                value="{{ $people }}"
                x-model.number="people"
                class="accent-primary mt-4 w-full cursor-pointer"
            >
            <div class="mt-1 flex justify-between text-sm text-gray-500 tabular-nums dark:text-gray-400" aria-hidden="true">
                <span>1</span>
                <span>{{ $maxPeople }}</span>
            </div>
        </div>

        <div>
            <label for="team-cost-seat" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('Per-seat price elsewhere') }}</label>
            <div class="mt-3 flex items-center gap-3">
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-sm text-gray-500 dark:text-gray-400" aria-hidden="true">$</span>
                    <input
                        id="team-cost-seat"
                        type="number"
                        min="0"
                        step="any"
                        inputmode="decimal"
                        value="{{ $seatPrice }}"
                        x-model="seat"
                        aria-describedby="team-cost-source"
                        class="bg-surface h-10 w-28 rounded-lg border border-gray-300 ps-7 pe-3 text-sm text-gray-950 tabular-nums focus:border-primary focus:outline-2 focus:outline-offset-0 focus:outline-primary/30 dark:border-white/20 dark:text-white"
                    >
                </div>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('per person a month') }}</span>
            </div>
            <p id="team-cost-source" class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                {{ __(':price is the median entry price of :count per-seat CRMs, billed yearly. Change it to what you pay today.', ['price' => $money($seatPrice), 'count' => count(CompetitorFacts::seatPrices())]) }}
            </p>
        </div>
    </div>

    <div class="bg-tint flex flex-col justify-between gap-10 p-6 md:p-10 lg:col-span-7">
        <div>
            <p class="type-label text-gray-500 dark:text-gray-400">{{ __('Per month, billed yearly') }}</p>
            <ul class="mt-6 grid grid-cols-[minmax(0,7.5rem)_minmax(0,1fr)_auto] gap-x-4 gap-y-5 sm:grid-cols-[9rem_minmax(0,1fr)_auto]">
                @foreach($bars as $bar)
                    @php
                        $amount = match ($bar['key']) {
                            'seat' => $seatTotal,
                            'pro' => (float) $pro,
                            'scale' => (float) $scale,
                        };
                    @endphp
                    <li class="col-span-3 grid grid-cols-subgrid items-center">
                        <p class="text-sm leading-tight">
                            <span class="block font-medium text-gray-950 dark:text-white">{{ $bar['label'] }}</span>
                            <span class="block text-gray-500 tabular-nums dark:text-gray-400">
                                @if($bar['key'] === 'seat')
                                    <span x-text="`${people} × ${money(seatPrice)}`">{{ $people }} × {{ $money($seatPrice) }}</span>
                                @else
                                    {{ __('Unlimited users') }}
                                @endif
                            </span>
                        </p>
                        <div class="h-8" aria-hidden="true">
                            <div
                                class="{{ $bar['fill'] }} h-full rounded-md motion-safe:transition-[clip-path] motion-safe:duration-300 motion-safe:ease-[cubic-bezier(0.22,1,0.36,1)]"
                                style="{{ $clip($amount) }}"
                                x-bind:style="{ clipPath: clip(amounts.{{ $bar['key'] }}) }"
                                data-team-cost-bar="{{ $bar['key'] }}"
                            ></div>
                        </div>
                        <p class="min-w-16 text-right text-sm font-medium text-gray-950 tabular-nums dark:text-white" x-text="money(amounts.{{ $bar['key'] }})" data-team-cost-amount="{{ $bar['key'] }}">{{ $money($amount) }}</p>
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="type-lead border-rail border-t pt-6 text-gray-950 dark:text-white" role="status" x-text="verdict" data-team-cost-verdict>
            {{ str_replace(':amount', $money(round(($seatTotal - $pro) * 12)), $copy['more']) }}
        </p>
    </div>
</div>
