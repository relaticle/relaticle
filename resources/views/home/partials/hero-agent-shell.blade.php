{{-- Mock Filament app shell sidebar — visible from md: up, hidden on mobile.
     Visually mirrors app.relaticle.test: white bg, dark workspace chip, light-gray
     active state with primary icon (not primary-tinted bg), and a "Chats" group
     at the bottom containing the active conversation.
     Icons use Heroicon outline to match the real Filament app exactly (the rest of
     the marketing site uses Remix Icon per project convention). --}}
<aside class="hero-agent-shell hidden md:flex md:w-48 lg:w-56 shrink-0 flex-col border-r border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
    {{-- Workspace switcher.
         The mark is a pixel-art "N" constructed from 13 discrete <rect>
         squares on a 5×5 grid: two full vertical columns plus three diagonal
         stair-step squares between them. Each cell is 4 units wide with a
         1-unit gap on a 24×24 viewBox, so the squares read as separate
         pixels rather than a solid letter. --}}
    <div class="flex items-center gap-2 px-3 py-2.5">
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
        <x-heroicon-o-chevron-down class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500"/>
    </div>

    {{-- Top-level nav items — icons match app/Filament/Resources/*Resource.php $navigationIcon.
         Home is the active page (mirrors the dashboard greeting "Good morning, …"); fi-active
         renders as a light-gray bg + primary icon, matching the real Filament sidebar. --}}
    <nav class="flex-1 overflow-hidden px-2 py-1 space-y-px text-sm">
        <div id="hero-shell-nav-home" class="flex items-center gap-2 rounded-md bg-gray-100 px-2 py-1.5 font-medium text-gray-900 dark:bg-white/[0.06] dark:text-white">
            <x-heroicon-o-home class="w-4 h-4 shrink-0 text-primary dark:text-primary-400"/>
            <span>Home</span>
        </div>
        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 text-gray-700 dark:text-gray-300">
            <x-heroicon-o-user class="w-4 h-4 shrink-0"/>
            <span>People</span>
        </div>
        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 text-gray-700 dark:text-gray-300">
            <x-heroicon-o-home-modern class="w-4 h-4 shrink-0"/>
            <span>Companies</span>
        </div>
        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 text-gray-700 dark:text-gray-300">
            <x-heroicon-o-trophy class="w-4 h-4 shrink-0"/>
            <span>Opportunities</span>
        </div>
        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 text-gray-700 dark:text-gray-300">
            <x-heroicon-o-check-circle class="w-4 h-4 shrink-0"/>
            <span>Tasks</span>
        </div>
        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 text-gray-700 dark:text-gray-300">
            <x-heroicon-o-document-text class="w-4 h-4 shrink-0"/>
            <span>Notes</span>
        </div>

        {{-- Chats group — recent conversations, mirroring chat-sidebar-nav.blade.php.
             None is active here because Home is the current page. --}}
        <div class="pt-3">
            <div class="flex items-center justify-between px-2 pb-1">
                <span class="text-pico font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">Chats</span>
                <x-heroicon-o-chevron-up class="w-3 h-3 text-gray-400 dark:text-gray-500"/>
            </div>

            @foreach ([
                'Overdue tasks this week',
                "This week's pipeline review",
                'Add Sarah Chen to Kovra Systems',
                'Follow up with Trellis Labs',
            ] as $heroChatTitle)
                <div class="flex items-center gap-2 rounded-md px-2 py-1.5 text-gray-700 dark:text-gray-300">
                    <x-heroicon-o-chat-bubble-left class="w-4 h-4 shrink-0"/>
                    <span class="truncate">{{ $heroChatTitle }}</span>
                </div>
            @endforeach

            {{-- All chats trigger — mirrors the "All chats" footer item in chat-sidebar-nav.blade.php --}}
            <div class="flex items-center gap-2 rounded-md px-2 py-1.5 text-gray-500 opacity-60 dark:text-gray-400">
                <x-heroicon-o-ellipsis-horizontal class="w-4 h-4 shrink-0"/>
                <span>All chats</span>
            </div>
        </div>
    </nav>
</aside>
