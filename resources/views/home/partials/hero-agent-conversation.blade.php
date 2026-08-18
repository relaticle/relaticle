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
    <div class="mcp-el mcp-avatar mcp-avatar-1 max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-3 text-sm text-gray-900 shadow-sm ring-1 ring-gray-200 dark:bg-zinc-800 dark:text-zinc-100 dark:ring-zinc-700">
        {{-- Empty label spacer so existing animation target stays valid --}}
        <span class="mcp-el mcp-label mcp-label-1 sr-only">Assistant</span>

        <div class="mcp-el mcp-tool mcp-tool-1 flex items-center gap-2 text-micro">
            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-zinc-500" aria-hidden="true"></span>
            <span class="font-medium text-gray-600 dark:text-zinc-300">Searching tasks…</span>
            <span class="mcp-el mcp-tool-done text-emerald-600 dark:text-emerald-400 font-medium">done</span>
        </div>

        <div class="mcp-el mcp-text mcp-text-1 mt-2 leading-relaxed text-gray-700 dark:text-zinc-200">
            You have 3 overdue tasks:
        </div>
    </div>

    {{-- Tool result table — sibling of the bubble. Mirrors data-table.blade.php:
         one container, rows separated by a hairline divider.
         mcp-el keeps the container hidden during reset so its outline doesn't
         ghost through before exchange 1 begins. --}}
    <div class="mcp-el mcp-tasks-table max-w-[85%] overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="divide-y divide-gray-100 dark:divide-zinc-700">
            <div class="mcp-el mcp-task-card mcp-task-1 flex items-center justify-between gap-3 px-3 py-2.5">
                <div class="flex items-center gap-2.5 min-w-0">
                    <x-heroicon-o-stop-circle class="w-3.5 h-3.5 text-gray-400 dark:text-zinc-500 shrink-0"/>
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">Call Sarah Chen</div>
                        <div class="text-xs text-gray-500 dark:text-zinc-400 mt-0.5">Due yesterday · Kovra Systems</div>
                    </div>
                </div>
                <span class="shrink-0 text-pico font-medium text-rose-600 dark:text-rose-400 uppercase tracking-wider">Overdue</span>
            </div>
            <div class="mcp-el mcp-task-card mcp-task-2 flex items-center justify-between gap-3 px-3 py-2.5">
                <div class="flex items-center gap-2.5 min-w-0">
                    <x-heroicon-o-stop-circle class="w-3.5 h-3.5 text-gray-400 dark:text-zinc-500 shrink-0"/>
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">Send proposal to Trellis Labs</div>
                        <div class="text-xs text-gray-500 dark:text-zinc-400 mt-0.5">Due 2 days ago · Trellis Labs</div>
                    </div>
                </div>
                <span class="shrink-0 text-pico font-medium text-rose-600 dark:text-rose-400 uppercase tracking-wider">Overdue</span>
            </div>
            <div class="mcp-el mcp-task-card mcp-task-3 flex items-center justify-between gap-3 px-3 py-2.5">
                <div class="flex items-center gap-2.5 min-w-0">
                    <x-heroicon-o-stop-circle class="w-3.5 h-3.5 text-gray-400 dark:text-zinc-500 shrink-0"/>
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">Schedule demo with Kovra Systems</div>
                        <div class="text-xs text-gray-500 dark:text-zinc-400 mt-0.5">Due 3 days ago · Kovra Systems</div>
                    </div>
                </div>
                <span class="shrink-0 text-pico font-medium text-rose-600 dark:text-rose-400 uppercase tracking-wider">Overdue</span>
            </div>
        </div>
    </div>
</div>

{{-- Exchange 2: a write gated by review (climax). The proposal docks at the
     composer (see hero-agent-composer.blade.php); once "saved", the audit card
     and the agent outcome land here — mirroring the real transcript. --}}
<div class="mcp-el mcp-user mcp-user-2 flex justify-end">
    <div class="max-w-[80%] rounded-2xl rounded-br-md bg-primary-600 px-4 py-3 text-sm text-white">
        Mark the Kovra demo as done.
    </div>
</div>

<div class="flex w-full flex-col items-start gap-3">
    <div class="mcp-el mcp-avatar mcp-avatar-2 max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-3 text-sm text-gray-900 shadow-sm ring-1 ring-gray-200 dark:bg-zinc-800 dark:text-zinc-100 dark:ring-zinc-700">
        <span class="mcp-el mcp-label mcp-label-2 sr-only">Assistant</span>

        <div class="mcp-el mcp-text mcp-text-2 leading-relaxed text-gray-700 dark:text-zinc-200">
            Review the proposal below to update the task.
        </div>
    </div>

    {{-- Audit card — appears once the docked proposal is saved, exactly like the
         real transcript's finalized proposal card. --}}
    <div class="mcp-el mcp-audit-card w-full max-w-[85%] rounded-xl border border-gray-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" aria-hidden="true">
        <div class="flex items-start gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400">
                <x-heroicon-o-pencil-square class="h-4 w-4"/>
            </div>
            <div class="min-w-0 flex-1 pt-1">
                <p class="text-sm font-semibold leading-5 text-gray-900 dark:text-white">Update task "Schedule demo with Kovra Systems"</p>
            </div>
            <span class="mt-0.5 inline-flex shrink-0 items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-400/10 dark:text-green-400">Approved</span>
        </div>
        <div class="mt-3 space-y-1.5 ps-11">
            <div class="flex items-start gap-3">
                <span class="w-28 shrink-0 pt-0.5 text-xs font-medium leading-5 text-gray-500 sm:w-32 dark:text-zinc-400">Status</span>
                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 text-sm">
                    <span class="text-gray-400 line-through decoration-gray-300 dark:text-zinc-500 dark:decoration-zinc-600">To do</span>
                    <x-heroicon-m-arrow-right class="h-3 w-3 text-gray-400 dark:text-zinc-500"/>
                    <span class="font-medium text-gray-900 dark:text-white">Done</span>
                </span>
            </div>
        </div>
    </div>

    {{-- Agent outcome — the sparkle summary bubble the real app renders below
         a finalized proposal. --}}
    <div class="mcp-el mcp-approve-done flex justify-start" aria-hidden="true">
        <div class="inline-flex max-w-full items-start gap-1.5 rounded-2xl rounded-bl-md bg-white px-3 py-2 text-sm text-gray-700 shadow-sm ring-1 ring-gray-200 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700">
            <x-heroicon-o-sparkles class="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary-500"/>
            <span>Updated Schedule demo with Kovra Systems.</span>
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
    <div class="mcp-el mcp-avatar mcp-avatar-3 max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-3 text-sm text-gray-900 shadow-sm ring-1 ring-gray-200 dark:bg-zinc-800 dark:text-zinc-100 dark:ring-zinc-700">
        <span class="mcp-el mcp-label mcp-label-3 sr-only">Assistant</span>

        <div class="mcp-el mcp-tool mcp-tool-3 flex items-center gap-2 text-micro">
            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-zinc-500" aria-hidden="true"></span>
            <span class="font-medium text-gray-600 dark:text-zinc-300">Creating contact…</span>
            <span class="mcp-el mcp-tool-done text-emerald-600 dark:text-emerald-400 font-medium">done</span>
        </div>

        <div class="mcp-el mcp-text mcp-text-3 mt-2 leading-relaxed text-gray-700 dark:text-zinc-200">
            Added Sarah and linked her to Kovra Systems.
        </div>
    </div>

    {{-- Created record card — sibling of the bubble --}}
    <div class="mcp-el mcp-card max-w-[85%] rounded-xl border border-gray-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Sarah Chen</div>
                <div class="text-xs text-gray-500 dark:text-zinc-400 mt-0.5">VP of Engineering · Kovra Systems</div>
            </div>
            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-rose-400 to-orange-300 dark:from-rose-500 dark:to-orange-400 shrink-0">
                <span class="text-pico font-bold text-white">SC</span>
            </div>
        </div>
    </div>
</div>
