<ul @class([
    'flex flex-col gap-2.5',
    'rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10' => $boxed ?? false,
])>
    @foreach ([
        'ri-user-add-line' => 'benefit_records',
        'ri-mail-line' => 'benefit_timeline',
        'ri-send-plane-line' => 'benefit_send',
    ] as $icon => $key)
        <li class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-300">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-gray-50 text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
                <x-filament::icon :icon="$icon" class="size-4" />
            </span>
            <span>{{ __('filament/pages/workspaces.setup_workspace.email.'.$key) }}</span>
        </li>
    @endforeach
</ul>
