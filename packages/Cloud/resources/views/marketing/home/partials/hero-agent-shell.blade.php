{{-- Mock of the app panel's sidebar, shown from md: up. Greys are spelled zinc because the
     marketing bundle's gray is Tailwind's cool gray, and the app panel's gray is zinc. --}}
@php
    $navRow = 'group flex items-center gap-2 rounded-lg px-2 py-1.5 font-medium text-zinc-700 data-active:bg-[var(--surface-sidebar-active-bg)] data-active:text-zinc-950 dark:text-zinc-300 dark:data-active:text-white';
    $navIcon = 'w-4 h-4 shrink-0 text-zinc-500 group-data-active:text-zinc-950 dark:text-zinc-400 dark:group-data-active:text-white';
    $chatRow = 'group flex items-center gap-2 rounded-lg px-2 py-1.5 text-zinc-600 data-active:bg-[var(--surface-sidebar-active-bg)] data-active:text-zinc-950 dark:text-zinc-400 dark:data-active:text-white';
    $chatIcon = 'w-4 h-4 shrink-0 text-zinc-400 group-data-active:text-zinc-950 dark:text-zinc-500 dark:group-data-active:text-white';
@endphp

<aside class="hero-agent-shell hidden md:flex md:w-48 lg:w-56 shrink-0 flex-col border-r border-zinc-200/60 bg-[var(--surface-sidebar-bg)] dark:border-white/10 [&_svg]:stroke-[1.75]">
    {{-- Workspace switcher. The real tenant avatar (fi-tenant-avatar) is a
         generated SVG: a solid black square with the workspace initials in
         white, centred. Reproduced here as markup rather than a data URI so it
         picks up the same dark-mode treatment as the rest of the mock. --}}
    <div class="flex h-10 shrink-0 items-center gap-2 border-b border-zinc-200/60 px-3 dark:border-white/10">
        <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-zinc-900 dark:bg-white/[0.1]">
            <span class="text-pico font-bold leading-none text-white">NW</span>
        </div>
        <div class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-950 dark:text-white">Northwind</div>
        <x-heroicon-o-chevron-down class="w-3.5 h-3.5 shrink-0 text-zinc-400 dark:text-zinc-500"/>
        <x-ri-sidebar-fold-line class="ms-1 h-4 w-4 shrink-0 text-zinc-400 dark:text-zinc-500"/>
    </div>

    {{-- Global search + notifications row, mirroring the real sidebar's
         fi-sidebar-search-ctn (GlobalSearch field + inbox trigger). --}}
    <div class="flex items-center gap-1.5 px-2 pt-2.5 pb-1.5">
        <div class="flex h-7 min-w-0 flex-1 items-center gap-1.5 rounded-lg bg-white px-2 shadow-xs ring-1 ring-zinc-950/10 dark:bg-white/5 dark:ring-white/10">
            <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5 shrink-0 text-zinc-500 dark:text-zinc-400"/>
            <span class="min-w-0 flex-1 truncate text-xs text-zinc-500 dark:text-zinc-400">Search</span>
            <kbd class="rounded-md bg-zinc-50 px-1 font-sans text-pico font-medium text-zinc-500 ring-1 ring-zinc-950/[0.06] dark:bg-white/10 dark:text-zinc-400 dark:ring-white/10">&#8984;K</kbd>
        </div>
        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-zinc-500 shadow-xs ring-1 ring-zinc-950/10 dark:bg-white/5 dark:text-zinc-400 dark:ring-white/10">
            <x-ri-inbox-line class="w-3.5 h-3.5"/>
        </div>
    </div>

    {{-- Icons match app/Filament/Resources/*Resource.php $navigationIcon. heroChat.setShellActive()
         moves data-active from Home to the first chat as the demo enters the conversation. --}}
    <nav class="flex-1 overflow-hidden px-2 py-1 space-y-px text-sm">
        <div id="hero-shell-nav-home" data-active class="{{ $navRow }}">
            <x-heroicon-o-home class="{{ $navIcon }}"/>
            <span>Home</span>
        </div>
        <div class="{{ $navRow }}">
            <x-heroicon-o-user class="{{ $navIcon }}"/>
            <span>People</span>
        </div>
        <div class="{{ $navRow }}">
            <x-heroicon-o-building-office class="{{ $navIcon }}"/>
            <span>Companies</span>
        </div>
        <div class="{{ $navRow }}">
            <x-heroicon-o-currency-dollar class="{{ $navIcon }}"/>
            <span>Opportunities</span>
        </div>
        <div class="{{ $navRow }}">
            <x-heroicon-o-clipboard-document-check class="{{ $navIcon }}"/>
            <span>Tasks</span>
        </div>
        <div class="{{ $navRow }}">
            <x-heroicon-o-document-text class="{{ $navIcon }}"/>
            <span>Notes</span>
        </div>

        {{-- Chats group: recent conversations, mirroring chat-sidebar-nav.blade.php. --}}
        <div class="pt-3">
            <div class="flex items-center justify-between px-2 pb-1">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Chats</span>
                <x-heroicon-o-chevron-up class="w-3 h-3 text-zinc-400 dark:text-zinc-500"/>
            </div>

            <div id="hero-shell-nav-chat" class="{{ $chatRow }}">
                <x-heroicon-o-chat-bubble-left class="{{ $chatIcon }}"/>
                <span class="truncate">Overdue tasks this week</span>
            </div>

            @foreach ([
                "This week's pipeline review",
                'Follow up with Priya Nair',
                'Renewal prep: Daniel Okafor',
            ] as $heroChatTitle)
                <div class="{{ $chatRow }}">
                    <x-heroicon-o-chat-bubble-left class="{{ $chatIcon }}"/>
                    <span class="truncate">{{ $heroChatTitle }}</span>
                </div>
            @endforeach

            <div class="{{ $chatRow }}">
                <x-heroicon-o-ellipsis-horizontal class="{{ $chatIcon }}"/>
                <span>All chats</span>
            </div>
        </div>
    </nav>
</aside>
