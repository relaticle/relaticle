<div class="sticky top-0 z-50 flex w-full items-center justify-between gap-3 border-b border-amber-300 bg-amber-100 px-3 py-1.5 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-200">
    <div class="flex min-w-0 items-center gap-1.5">
        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="size-4 shrink-0" />

        <span class="truncate">
            {{ __('filament/panel.impersonation.banner', ['name' => $user->name, 'email' => $user->email]) }}
        </span>
    </div>

    <form method="POST" action="{{ route('impersonation.stop') }}" class="shrink-0">
        @csrf
        @method('DELETE')

        <button type="submit" class="font-medium underline underline-offset-2">
            {{ __('filament/panel.impersonation.stop') }}
        </button>
    </form>
</div>
