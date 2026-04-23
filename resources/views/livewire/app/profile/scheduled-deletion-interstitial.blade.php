<div class="flex min-h-screen flex-col bg-gray-50 dark:bg-gray-950">
    @php
        $user = auth()->user();
        $deletionDate = $user->scheduled_deletion_at;
        $daysRemaining = (int) now()->diffInDays($deletionDate, absolute: false);
        $teamCount = $user->ownedTeams()->count();
    @endphp

    {{-- Centered logo --}}
    <div class="flex justify-center pt-10">
        <x-brand.logo-lockup size="md" class="text-gray-900 dark:text-white" />
    </div>

    {{-- Card --}}
    <div class="flex flex-1 items-start justify-center px-4 pt-8 pb-16">
        <div class="w-full max-w-md">
            <div class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

                {{-- Icon --}}
                <div class="mx-auto mb-5 flex size-12 items-center justify-center rounded-full bg-danger-50 dark:bg-danger-500/10">
                    <x-filament::icon icon="ri-delete-bin-line" class="size-6 text-danger-600 dark:text-danger-400" />
                </div>

                {{-- Heading --}}
                <h1 class="text-center text-lg font-semibold text-gray-950 dark:text-white">
                    Your account is being deleted
                </h1>

                {{-- Countdown --}}
                <div class="mx-auto mt-4 w-fit rounded-lg bg-danger-50 px-4 py-2 dark:bg-danger-500/10">
                    <p class="text-center text-sm font-medium text-danger-700 dark:text-danger-300">
                        @if ($daysRemaining > 0)
                            {{ $daysRemaining }} {{ Str::plural('day', $daysRemaining) }} remaining
                        @elseif ($daysRemaining === 0)
                            Deletion is scheduled for today
                        @else
                            Deletion is overdue
                        @endif
                    </p>
                </div>

                {{-- Details --}}
                <div class="mt-5 space-y-2 text-center text-sm text-gray-500 dark:text-gray-400">
                    <p>
                        Your account and all associated data will be permanently deleted on
                        <strong class="text-gray-950 dark:text-white">{{ $deletionDate->format('F j, Y') }}</strong>.
                    </p>
                    <p>
                        This includes {{ $teamCount }} {{ Str::plural('workspace', $teamCount) }}, contacts, companies, opportunities, and notes.
                    </p>
                </div>

                {{-- Actions --}}
                <div class="mt-6 space-y-3">
                    {{ $this->cancelDeletionAction }}
                    <div class="text-center">
                        {{ $this->logoutAction }}
                    </div>
                </div>
            </div>

            {{-- Help text --}}
            <p class="mt-4 text-center text-xs text-gray-400 dark:text-gray-500">
                Changed your mind? Cancel the deletion above and your account will be fully restored.
            </p>
        </div>
    </div>

    {{-- Footer --}}
    <div class="flex items-center justify-center gap-x-1 py-6 text-xs text-gray-400 dark:text-gray-500">
        <span>&copy; {{ date('Y') }} Relaticle</span>
        <span>&middot;</span>
        <a href="{{ url('/privacy-policy') }}" class="hover:text-gray-600 dark:hover:text-gray-300">Privacy Policy</a>
        <span>&middot;</span>
        <a href="{{ url('/terms-of-service') }}" class="hover:text-gray-600 dark:hover:text-gray-300">Terms</a>
    </div>

    <x-filament-actions::modals />
</div>
