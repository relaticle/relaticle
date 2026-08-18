{{-- Mock Filament app shell sidebar — visible from md: up, hidden on mobile.
     Visually mirrors app.relaticle.test: white bg, dark workspace chip, light-gray
     active state with primary icon (not primary-tinted bg), and a "Chats" group
     at the bottom containing the active conversation.
     Icons use Heroicon outline to match the real Filament app exactly (the rest of
     the marketing site uses Remix Icon per project convention). --}}
<aside class="hero-agent-shell hidden md:flex md:w-48 lg:w-56 shrink-0 flex-col border-r border-gray-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    {{-- Workspace switcher.
         The mark is a pixel-art "N" constructed from 13 discrete <rect>
         squares on a 5×5 grid: two full vertical columns plus three diagonal
         stair-step squares between them. Each cell is 4 units wide with a
         1-unit gap on a 24×24 viewBox, so the squares read as separate
         pixels rather than a solid letter. --}}
    <div class="flex items-center gap-2 px-3 pt-2.5 pb-2">
        <div class="flex h-6 w-6 items-center justify-center rounded bg-gray-900 shrink-0 dark:bg-white/[0.1]">
            <svg viewBox="0 0 24 24" class="h-3 w-3 text-white" fill="currentColor" aria-hidden="true" shape-rendering="crispEdges">
                {{-- Left column --}}
                <rect x="0"  y="0"  width="4" height="4"/>
                <rect x="0"  y="5"  width="4" height="4"/>
                <rect x="0"  y="10" width="4" height="4"/>
                <rect x="0"  y="15" width="4" height="4"/>
                <rect x="0"  y="20" width="4" height="4"/>
                {{-- Diagonal stair --}}
                <rect x="5"  y="5"  width="4" height="4"/>
                <rect x="10" y="10" width="4" height="4"/>
                <rect x="15" y="15" width="4" height="4"/>
                {{-- Right column --}}
                <rect x="20" y="0"  width="4" height="4"/>
                <rect x="20" y="5"  width="4" height="4"/>
                <rect x="20" y="10" width="4" height="4"/>
                <rect x="20" y="15" width="4" height="4"/>
                <rect x="20" y="20" width="4" height="4"/>
            </svg>
        </div>
        <div class="flex-1 min-w-0">
            <div class="text-sm font-semibold text-gray-900 dark:text-white truncate">Northwind</div>
        </div>
        <x-heroicon-o-chevron-down class="w-3.5 h-3.5 text-gray-400 dark:text-zinc-500"/>
    </div>

    {{-- Global search + notifications row — mirrors the real sidebar's
         fi-sidebar-search-ctn (GlobalSearch pill + inbox trigger). --}}
    <div class="flex items-center gap-1.5 px-2 pb-1.5">
        <div class="flex h-7 min-w-0 flex-1 items-center gap-1.5 rounded-full bg-gray-100 px-2.5 dark:bg-white/[0.06]">
            <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5 shrink-0 text-gray-400 dark:text-zinc-500"/>
            <span class="min-w-0 flex-1 truncate text-xs text-gray-400 dark:text-zinc-500">Search</span>
            <kbd class="rounded border border-gray-200 bg-white px-1 font-sans text-pico font-medium text-gray-400 dark:border-white/10 dark:bg-white/[0.06] dark:text-zinc-500">&#8984;K</kbd>
        </div>
        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-400 dark:border-white/10 dark:text-zinc-500">
            <x-heroicon-o-inbox class="w-3.5 h-3.5"/>
        </div>
    </div>

    {{-- Top-level nav items — icons match app/Filament/Resources/*Resource.php $navigationIcon.
         The active item renders as gray-100 bg + primary-700 label AND icon (measured from
         the live Filament sidebar); inactive icons are a step lighter than their labels.
         Which item is active is swapped by heroChat.setShellActive() as the demo moves
         from the dashboard (Home) into the conversation (first chat), like the real app. --}}
    <nav class="flex-1 overflow-hidden px-2 py-1 space-y-px text-sm">
        <div id="hero-shell-nav-home" class="flex items-center gap-2 rounded-lg px-2 py-1.5 bg-gray-100 font-medium text-primary-700 dark:bg-zinc-800 dark:text-primary-400">
            <x-heroicon-o-home class="w-4 h-4 shrink-0 text-primary-700 dark:text-primary-400"/>
            <span>Home</span>
        </div>
        <div class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-gray-700 dark:text-zinc-200">
            <x-heroicon-o-user class="w-4 h-4 shrink-0 text-gray-400 dark:text-zinc-500"/>
            <span>People</span>
        </div>
        <div class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-gray-700 dark:text-zinc-200">
            <x-heroicon-o-home-modern class="w-4 h-4 shrink-0 text-gray-400 dark:text-zinc-500"/>
            <span>Companies</span>
        </div>
        <div class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-gray-700 dark:text-zinc-200">
            <x-heroicon-o-trophy class="w-4 h-4 shrink-0 text-gray-400 dark:text-zinc-500"/>
            <span>Opportunities</span>
        </div>
        <div class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-gray-700 dark:text-zinc-200">
            <x-heroicon-o-check-circle class="w-4 h-4 shrink-0 text-gray-400 dark:text-zinc-500"/>
            <span>Tasks</span>
        </div>
        <div class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-gray-700 dark:text-zinc-200">
            <x-heroicon-o-document-text class="w-4 h-4 shrink-0 text-gray-400 dark:text-zinc-500"/>
            <span>Notes</span>
        </div>

        {{-- Chats group — recent conversations, mirroring chat-sidebar-nav.blade.php.
             None is active here because Home is the current page. --}}
        <div class="pt-3">
            <div class="flex items-center justify-between px-2 pb-1">
                <span class="text-sm font-medium text-gray-500 dark:text-zinc-400">Chats</span>
                <x-heroicon-o-chevron-up class="w-3 h-3 text-gray-400 dark:text-zinc-500"/>
            </div>

            <div id="hero-shell-nav-chat" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-gray-700 dark:text-zinc-200">
                <x-heroicon-o-chat-bubble-left class="w-4 h-4 shrink-0 text-gray-400 dark:text-zinc-500"/>
                <span class="truncate">Overdue tasks this week</span>
            </div>

            @foreach ([
                "This week's pipeline review",
                'Follow up with Priya Nair',
                'Renewal prep — Daniel Okafor',
            ] as $heroChatTitle)
                <div class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-gray-700 dark:text-zinc-200">
                    <x-heroicon-o-chat-bubble-left class="w-4 h-4 shrink-0 text-gray-400 dark:text-zinc-500"/>
                    <span class="truncate">{{ $heroChatTitle }}</span>
                </div>
            @endforeach

            {{-- All chats trigger — mirrors the "All chats" footer item in chat-sidebar-nav.blade.php --}}
            <div class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-gray-500 opacity-60 dark:text-zinc-400">
                <x-heroicon-o-ellipsis-horizontal class="w-4 h-4 shrink-0"/>
                <span>All chats</span>
            </div>
        </div>
    </nav>
</aside>
