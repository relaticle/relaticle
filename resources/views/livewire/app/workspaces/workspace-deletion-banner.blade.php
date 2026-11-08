<div>
    <div class="flex w-full items-center justify-between gap-3 border-b border-danger-200 bg-danger-50 px-4 py-2 dark:border-danger-400/20 dark:bg-danger-400/[0.06]">
        <div class="flex min-w-0 items-center gap-2">
            <x-filament::icon icon="lucide-trash" class="size-4 shrink-0 text-danger-500 dark:text-danger-400" />

            <p class="text-xs font-medium text-danger-800 dark:text-danger-300">
                {{ __('workspaces.deletion_banner.message', ['date' => $workspace->scheduled_deletion_at->format('F j, Y')]) }}
            </p>
        </div>

        @if($this->cancelDeletionAction->isVisible())
            {{ $this->cancelDeletionAction }}
        @endif
    </div>

    <x-filament-actions::modals />
</div>
