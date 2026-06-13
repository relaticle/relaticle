<div class="flex min-h-screen flex-col bg-gray-50 dark:bg-gray-950">
    @php
        $user = auth()->user();
        $deletionDate = $user->scheduled_deletion_at;
        $daysRemaining = (int) now()->diffInDays($deletionDate, absolute: false);
        $teamCount = $user->ownedTeams()->count();
    @endphp

    <div class="flex flex-1 items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            {{-- Logo --}}
            <div class="mb-8 flex justify-center">
                <x-brand.logo-lockup size="md" class="text-gray-900 dark:text-white" />
            </div>

            {{-- Card --}}
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                {{-- Header --}}
                <div class="flex flex-col items-center gap-4 px-8 pt-8 text-center">
                    <div class="flex size-12 items-center justify-center rounded-full bg-danger-50 dark:bg-danger-500/10">
                        <x-filament::icon icon="ri-delete-bin-line" class="size-6 text-danger-600 dark:text-danger-400" />
                    </div>

                    <h1 class="text-lg font-semibold text-gray-950 dark:text-white">
                        Your account is being deleted
                    </h1>
                </div>

                {{-- Countdown --}}
                <div class="mx-8 mt-6 rounded-xl bg-danger-50 px-6 py-5 text-center dark:bg-danger-500/10">
                    @if ($daysRemaining > 0)
                        <div class="flex items-baseline justify-center gap-2">
                            <span class="text-4xl font-bold tabular-nums leading-none text-danger-600 dark:text-danger-400">{{ $daysRemaining }}</span>
                            <span class="text-sm font-medium text-danger-700 dark:text-danger-300">{{ Str::plural('day', $daysRemaining) }} remaining</span>
                        </div>
                        <p class="mt-2 text-xs text-danger-700/80 dark:text-danger-300/80">
                            Permanent deletion on {{ $deletionDate->format('F j, Y') }}
                        </p>
                    @elseif ($daysRemaining === 0)
                        <p class="text-sm font-semibold text-danger-700 dark:text-danger-300">
                            Deletion is scheduled for today
                        </p>
                    @else
                        <p class="text-sm font-semibold text-danger-700 dark:text-danger-300">
                            Deletion is overdue
                        </p>
                    @endif
                </div>

                {{-- Details --}}
                <p class="mt-5 px-8 text-center text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                    This permanently removes
                    <strong class="font-medium text-gray-700 dark:text-gray-300">{{ $teamCount }} {{ Str::plural('workspace', $teamCount) }}</strong>
                    along with every contact, company, opportunity, and note.
                </p>

                {{-- Actions --}}
                <div class="mt-7 space-y-3 border-t border-gray-100 px-8 py-6 dark:border-white/5">
                    {{ $this->cancelDeletionAction }}

                    <div class="text-center">
                        {{ $this->logoutAction }}
                    </div>
                </div>
            </div>

            {{-- Help text --}}
            <p class="mt-5 text-center text-xs text-gray-400 dark:text-gray-500">
                Changed your mind? Keep your account above and everything is restored instantly.
            </p>
        </div>
    </div>

    {{-- Footer --}}
    <div class="flex items-center justify-center gap-x-1 py-6 text-xs text-gray-400 dark:text-gray-500">
        <span>&copy; {{ date('Y') }} Relaticle</span>
        <span>&middot;</span>
        <a href="{{ url('/privacy-policy') }}" class="transition hover:text-gray-600 dark:hover:text-gray-300">Privacy Policy</a>
        <span>&middot;</span>
        <a href="{{ url('/terms-of-service') }}" class="transition hover:text-gray-600 dark:hover:text-gray-300">Terms</a>
    </div>

    <x-filament-actions::modals />
</div>
