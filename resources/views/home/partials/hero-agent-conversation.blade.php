{{-- Exchange 1: read overdue tasks --}}
{{-- User msg: right-aligned purple pill --}}
<div class="mcp-el mcp-user mcp-user-1 flex justify-end">
    <div class="max-w-[80%] rounded-2xl rounded-br-md bg-primary-600 px-4 py-3 text-sm text-white">
        What's overdue this week?
    </div>
</div>

{{-- Assistant block: bubble + sibling tool-result card.
     Mirrors the real chat-interface.blade.php pattern where pending_actions /
     paywall cards render as SIBLINGS of the assistant bubble, not nested
     inside it. Avoids the card-in-card feel. --}}
<div class="flex flex-col items-start gap-3">
    <div class="mcp-el mcp-avatar mcp-avatar-1 max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-3 text-sm text-gray-900 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700">
        {{-- Empty label spacer so existing animation target stays valid --}}
        <span class="mcp-el mcp-label mcp-label-1 sr-only">Assistant</span>

        <div class="mcp-el mcp-tool mcp-tool-1 flex items-center gap-2 text-micro">
            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500" aria-hidden="true"></span>
            <span class="font-medium text-gray-600 dark:text-gray-300">Searching tasks…</span>
            <span class="text-emerald-600 dark:text-emerald-400 font-medium">done</span>
        </div>

        <div class="mcp-el mcp-text mcp-text-1 mt-2 leading-relaxed text-gray-700 dark:text-gray-200">
            You have 3 overdue tasks:
        </div>
    </div>

    {{-- Tool result table — sibling of the bubble. Mirrors data-table.blade.php:
         one container, rows separated by a hairline divider.
         mcp-el keeps the container hidden during reset so its outline doesn't
         ghost through before exchange 1 begins. --}}
    <div class="mcp-el mcp-tasks-table max-w-[85%] overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            <div class="mcp-el mcp-task-card mcp-task-1 flex items-center justify-between px-3 py-2.5">
                <div class="flex items-center gap-2.5">
                    <x-heroicon-o-stop-circle class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500 shrink-0"/>
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">Call Sarah Chen</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Due yesterday · Kovra Systems</div>
                    </div>
                </div>
                <span class="text-pico font-medium text-rose-600 dark:text-rose-400 uppercase tracking-wider">Overdue</span>
            </div>
            <div class="mcp-el mcp-task-card mcp-task-2 flex items-center justify-between px-3 py-2.5">
                <div class="flex items-center gap-2.5">
                    <x-heroicon-o-stop-circle class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500 shrink-0"/>
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">Send proposal to Trellis Labs</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Due 2 days ago · Trellis Labs</div>
                    </div>
                </div>
                <span class="text-pico font-medium text-rose-600 dark:text-rose-400 uppercase tracking-wider">Overdue</span>
            </div>
            <div class="mcp-el mcp-task-card mcp-task-3 flex items-center justify-between px-3 py-2.5">
                <div class="flex items-center gap-2.5">
                    <x-heroicon-o-stop-circle class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500 shrink-0"/>
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">Schedule demo with Kovra Systems</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Due 3 days ago · Kovra Systems</div>
                    </div>
                </div>
                <span class="text-pico font-medium text-rose-600 dark:text-rose-400 uppercase tracking-wider">Overdue</span>
            </div>
        </div>
    </div>
</div>

{{-- Exchange 2: destructive op gated by approval (climax) --}}
<div class="mcp-el mcp-user mcp-user-2 flex justify-end">
    <div class="max-w-[80%] rounded-2xl rounded-br-md bg-primary-600 px-4 py-3 text-sm text-white">
        Mark them all as done.
    </div>
</div>

<div class="flex flex-col items-start gap-3">
    <div class="mcp-el mcp-avatar mcp-avatar-2 max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-3 text-sm text-gray-900 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700">
        <span class="mcp-el mcp-label mcp-label-2 sr-only">Assistant</span>

        <div class="mcp-el mcp-text mcp-text-2 leading-relaxed text-gray-700 dark:text-gray-200">
            I'll mark 3 tasks complete. Confirm to proceed.
        </div>
    </div>

    {{-- Pending action card — sibling of the bubble (matches real app pattern) --}}
    <div class="mcp-el mcp-action-card max-w-[85%] rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900" aria-hidden="true">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">Update</span>
            <span class="text-sm font-medium text-gray-900 dark:text-white">Mark 3 tasks complete</span>
        </div>
        <div class="mt-2 space-y-1">
            <div class="flex gap-2 text-sm">
                <span class="font-medium text-gray-500 dark:text-gray-400">Tasks:</span>
                <span class="text-gray-900 dark:text-white">Call Sarah Chen · Send proposal · Schedule demo</span>
            </div>
        </div>
        <div class="mt-3 flex items-center gap-2">
            <button type="button" tabindex="-1" class="inline-flex items-center gap-1 rounded-lg bg-green-600 px-3 py-1.5 text-xs font-medium text-white">
                <x-heroicon-o-check class="w-3.5 h-3.5"/>
                Approve
            </button>
            <button type="button" tabindex="-1" class="inline-flex items-center gap-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white">
                <x-heroicon-o-x-mark class="w-3.5 h-3.5"/>
                Reject
            </button>
        </div>
    </div>
</div>

{{-- Exchange 3: create with @-mention --}}
<div class="mcp-el mcp-user mcp-user-3 flex justify-end">
    <div class="max-w-[80%] rounded-2xl rounded-br-md bg-primary-600 px-4 py-3 text-sm leading-relaxed text-white">
        Add Sarah Chen as a contact at <span class="inline-flex items-center rounded-md bg-primary-100 px-1.5 py-0.5 text-xs font-medium text-primary-800 align-baseline dark:bg-primary-900/30 dark:text-primary-200">@Kovra Systems</span>. She's VP of Engineering.
    </div>
</div>

<div class="flex flex-col items-start gap-3">
    <div class="mcp-el mcp-avatar mcp-avatar-3 max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-3 text-sm text-gray-900 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700">
        <span class="mcp-el mcp-label mcp-label-3 sr-only">Assistant</span>

        <div class="mcp-el mcp-tool mcp-tool-3 flex items-center gap-2 text-micro">
            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500" aria-hidden="true"></span>
            <span class="font-medium text-gray-600 dark:text-gray-300">Creating contact…</span>
            <span class="text-emerald-600 dark:text-emerald-400 font-medium">done</span>
        </div>

        <div class="mcp-el mcp-text mcp-text-3 mt-2 leading-relaxed text-gray-700 dark:text-gray-200">
            Added Sarah and linked her to Kovra Systems.
        </div>
    </div>

    {{-- Created record card — sibling of the bubble --}}
    <div class="mcp-el mcp-card max-w-[85%] rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Sarah Chen</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">VP of Engineering · Kovra Systems</div>
            </div>
            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-rose-400 to-orange-300 dark:from-rose-500 dark:to-orange-400 shrink-0">
                <span class="text-pico font-bold text-white">SC</span>
            </div>
        </div>
    </div>
</div>
