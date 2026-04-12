@php
    use App\Models\Note;
    use Illuminate\Support\Carbon;
    use Relaticle\EmailIntegration\Enums\EmailDirection;
    use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
    use Relaticle\EmailIntegration\Models\Email;

    /**
     * Group activities into ordered buckets.
     * Keys are prefixed with a numeric sort token so ksort() yields the right order.
     *
     * @param Carbon|null $date
     */
    $bucketKey = function (?Carbon $date): string {
        if ($date === null) {
            return '9_unknown';
        }
        if ($date->isToday()) {
            return '0_today';
        }
        if ($date->isYesterday()) {
            return '1_yesterday';
        }
        if ($date->greaterThanOrEqualTo(now()->startOfWeek())) {
            return '2_this_week';
        }
        if ($date->isSameMonth(now())) {
            return '3_this_month';
        }

        // Older: "2024-03" so ksort() keeps months in reverse-chronological order
        return '4_' . $date->format('Y-m');
    };

    $bucketLabel = function (string $key): string {
        return match ($key) {
            '0_today'      => 'Today',
            '1_yesterday'  => 'Yesterday',
            '2_this_week'  => 'This Week',
            '3_this_month' => 'This Month',
            '9_unknown'    => 'Unknown',
            default        => Carbon::createFromFormat('Y-m', substr($key, 2))?->format('F Y') ?? substr($key, 2),
        };
    };

    $grouped = $activities
        ->groupBy(fn (array $item): string => $bucketKey($item['date']))
        ->sortKeys();

    $aiLabelColor = fn (?string $label): string => match ($label) {
        'Scheduling' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
        'Marketing'  => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        'Invoice'    => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'Support'    => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'Sales'      => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
        default      => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    };
@endphp

<div class="flex flex-col">

    {{-- ── Empty state ─────────────────────────────────────────────────────── --}}
    @if ($activities->isEmpty())
        <div class="flex flex-col items-center gap-4 px-8 py-16 text-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                <x-heroicon-o-clock class="h-6 w-6 text-gray-400 dark:text-gray-500" />
            </div>
            <div class="space-y-1">
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">No activity yet</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Emails and notes will appear here as they are added.</p>
            </div>
        </div>
    @else
        @foreach ($grouped as $bucketKey => $items)

            {{-- ── Date group header ────────────────────────────────────────── --}}
            <div class="sticky top-0 z-10 border-b border-gray-100 dark:border-gray-800 bg-gray-50/90 dark:bg-gray-900/80 backdrop-blur-sm px-6 py-2">
                <span class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">
                    {{ $bucketLabel($bucketKey) }}
                </span>
            </div>

            {{-- ── Activity items ───────────────────────────────────────────── --}}
            <div class="relative divide-y divide-gray-50 dark:divide-gray-800/60">

                {{-- Vertical timeline line --}}
                <div class="absolute inset-y-0 left-[3.25rem] w-px bg-gray-100 dark:bg-gray-800 pointer-events-none"></div>

                @foreach ($items as $item)
                    @php
                        /** @var Email|Note $record */
                        $record = $item['record'];
                        /** @var Carbon|null $date */
                        $date = $item['date'];
                    @endphp

                    @if ($item['type'] === 'email')
                        @php
                            /** @var Email $record */
                            $from      = $record->participants->firstWhere('role', EmailParticipantRole::FROM);
                            $aiLabel   = $record->labels->firstWhere('source', 'ai');
                            $isInbound = $record->direction === EmailDirection::INBOUND;
                            $canViewSubject = $authUser->can('viewSubject', $record);
                            $senderDisplay  = $from?->name ?: $from?->email_address ?: '(unknown sender)';
                        @endphp

                        <div class="group flex items-start gap-4 px-6 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">

                            {{-- Icon --}}
                            <div @class([
                                'relative z-10 mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-2 ring-white dark:ring-gray-900',
                                'bg-sky-100 dark:bg-sky-900/40'    => $isInbound,
                                'bg-violet-100 dark:bg-violet-900/40' => ! $isInbound,
                            ])>
                                <x-heroicon-o-envelope @class([
                                    'h-3.5 w-3.5',
                                    'text-sky-600 dark:text-sky-400'       => $isInbound,
                                    'text-violet-600 dark:text-violet-400' => ! $isInbound,
                                ]) />
                            </div>

                            {{-- Content --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">

                                    {{-- Subject --}}
                                    <p class="truncate text-sm font-medium leading-snug text-gray-900 dark:text-white">
                                        @if ($canViewSubject)
                                            {{ $record->subject ?? '(no subject)' }}
                                        @else
                                            <span class="italic text-gray-400 dark:text-gray-500">(subject hidden)</span>
                                        @endif
                                    </p>

                                    {{-- Time --}}
                                    @if ($date)
                                        <time class="shrink-0 text-xs text-gray-400 dark:text-gray-500 tabular-nums">
                                            {{ $date->format('g:i A') }}
                                        </time>
                                    @endif
                                </div>

                                {{-- Meta row --}}
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $senderDisplay }}</span>

                                    {{-- Direction badge --}}
                                    <span @class([
                                        'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset',
                                        'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-400 dark:ring-sky-400/20'          => $isInbound,
                                        'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-400/10 dark:text-violet-400 dark:ring-violet-400/20' => ! $isInbound,
                                    ])>
                                        {{ $record->direction->getLabel() }}
                                    </span>

                                    {{-- AI label badge --}}
                                    @if ($aiLabel)
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ring-transparent {{ $aiLabelColor($aiLabel->label) }}">
                                            {{ $aiLabel->label }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                    @elseif ($item['type'] === 'note')
                        @php
                            /** @var Note $record */
                            $creatorName = $record->creator?->name ?? 'System';
                        @endphp

                        <div class="group flex items-start gap-4 px-6 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">

                            {{-- Icon --}}
                            <div class="relative z-10 mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/40 ring-2 ring-white dark:ring-gray-900">
                                <x-heroicon-o-document-text class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" />
                            </div>

                            {{-- Content --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="truncate text-sm font-medium leading-snug text-gray-900 dark:text-white">
                                        {{ $record->title }}
                                    </p>

                                    @if ($date)
                                        <time class="shrink-0 text-xs text-gray-400 dark:text-gray-500 tabular-nums">
                                            {{ $date->format('g:i A') }}
                                        </time>
                                    @endif
                                </div>

                                <div class="mt-1 flex items-center gap-x-2">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $creatorName }}</span>
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-400 dark:ring-amber-400/20">
                                        Note
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endif

                @endforeach
            </div>

        @endforeach
    @endif

</div>
