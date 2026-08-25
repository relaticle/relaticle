{{-- Static twin of the real chat transcript. Every surface below is copied from
     packages/Chat/resources/views/livewire/chat/partials/, which is the ONLY
     source of truth for how a message, a result block or a proposal looks:

       user bubble      _transcript.blade.php  (soft primary tint, never solid)
       assistant reply  _transcript.blade.php  (flat prose, no bubble)
       records table    _block-records-table.blade.php
       record card      _block-record-card.blade.php
       proposal row     _proposal-card-body.blade.php (decided = ONE line)
       plan card        _proposal-plan-card.blade.php

     Those partials are Alpine-driven; this file is their rendered shape frozen
     as markup. When one of them changes, this file changes with it -- a hero
     tab that mirrors a version of the product we no longer ship is a bug
     (.ai/guidelines/relaticle/ui.md: mockups mirror the real app UI 1:1). --}}

@php
    // Same paths chat.js feeds to _block-records-table / _block-record-card via
    // window.ChatModules.recordChipIcon(). Kept in the two shapes the real chip
    // markup needs so a glyph here can never drift from a glyph there.
    $heroChipIcons = [
        'task' => 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75',
        'people' => 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
        'company' => 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
    ];
@endphp

{{-- ── Exchange 1: read overdue tasks ── --}}

{{-- User bubble. Soft brand tint + inset ring, NOT solid primary-600: see the
     comment on _transcript.blade.php's bubble. A wall of saturated bubbles
     overpowers the transcript, which is why the product moved off it. --}}
<div class="mcp-el mcp-user mcp-user-1 flex justify-end">
    <div class="max-w-[85%] rounded-2xl rounded-br-md bg-primary-50 px-4 py-2.5 text-sm text-gray-900 ring-1 ring-inset ring-primary-600/10 dark:bg-primary-500/15 dark:text-gray-100 dark:ring-primary-400/15">
        What's overdue this week?
    </div>
</div>

{{-- Assistant turn. The reply is flat prose at full width with no bubble,
     ring or shadow; the result block is a sibling below it. --}}
<div class="flex w-full flex-col items-start gap-3">
    <div class="mcp-el mcp-avatar mcp-avatar-1 w-full min-w-0">
        <span class="mcp-el mcp-label mcp-label-1 sr-only">Assistant</span>

        {{-- Tool status: the same dot + label row the transcript shows while a
             tool runs (data-chat-loading-indicator). --}}
        <div class="mcp-el mcp-tool mcp-tool-1 flex items-center gap-2 px-1 py-1 text-xs">
            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500" aria-hidden="true"></span>
            <span class="text-gray-600 dark:text-gray-300">Searching tasks…</span>
        </div>

        <div class="mcp-el mcp-text mcp-text-1 px-1 py-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100">
            You have 3 overdue tasks, all past due this week.
        </div>
    </div>

    {{-- records_table block. Full width, header strip (glyph + title), uppercase
         column labels, and the core column rendered as a chat-chip link. --}}
    <div class="mcp-el mcp-tasks-table my-3 w-full overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
            <span class="flex min-w-0 items-center gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['task'] }}"/>
                    </svg>
                </span>
                <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">Tasks</span>
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        @foreach (['Title', 'Due date', 'Company'] as $heroColumn)
                            <th scope="col" class="whitespace-nowrap px-4 py-2 text-start text-micro font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {{ $heroColumn }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ([
                        ['row' => 'mcp-task-1', 'title' => 'Call Sarah Chen', 'due' => 'Aug 24, 2026', 'company' => 'Kovra Systems'],
                        ['row' => 'mcp-task-2', 'title' => 'Send proposal to Trellis Labs', 'due' => 'Aug 23, 2026', 'company' => 'Trellis Labs'],
                        ['row' => 'mcp-task-3', 'title' => 'Schedule demo with Kovra Systems', 'due' => 'Aug 22, 2026', 'company' => 'Kovra Systems'],
                    ] as $heroTaskRow)
                        <tr class="mcp-el mcp-task-card {{ $heroTaskRow['row'] }}">
                            <td class="max-w-48 px-4 py-2.5 align-top text-sm text-gray-700 dark:text-gray-300">
                                <span class="chat-chip" data-record-type="task">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['task'] }}"/>
                                    </svg>
                                    <span class="chat-chip-label">{{ $heroTaskRow['title'] }}</span>
                                </span>
                            </td>
                            <td class="max-w-48 px-4 py-2.5 align-top text-sm text-gray-700 dark:text-gray-300">
                                <span class="block truncate">{{ $heroTaskRow['due'] }}</span>
                            </td>
                            <td class="max-w-48 px-4 py-2.5 align-top text-sm text-gray-700 dark:text-gray-300">
                                <span class="chat-chip" data-record-type="company">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['company'] }}"/>
                                    </svg>
                                    <span class="chat-chip-label">{{ $heroTaskRow['company'] }}</span>
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── Exchange 2: a write gated by review (the climax) ──
     The proposal docks at the composer (hero-agent-composer.blade.php); once
     approved it collapses into the transcript as a ONE-LINE decided row, and
     the agent's own reply lands under it. --}}
<div class="mcp-el mcp-user mcp-user-2 flex justify-end">
    <div class="max-w-[85%] rounded-2xl rounded-br-md bg-primary-50 px-4 py-2.5 text-sm text-gray-900 ring-1 ring-inset ring-primary-600/10 dark:bg-primary-500/15 dark:text-gray-100 dark:ring-primary-400/15">
        Mark the Kovra demo as done.
    </div>
</div>

<div class="flex w-full flex-col items-start gap-3">
    <div class="mcp-el mcp-avatar mcp-avatar-2 w-full min-w-0">
        <span class="mcp-el mcp-label mcp-label-2 sr-only">Assistant</span>

        <div class="mcp-el mcp-text mcp-text-2 px-1 py-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100">
            Review the proposal below to update the task.
        </div>
    </div>

    {{-- Decided proposal, collapsed to one line: record pill, operation, record
         link, outcome chip, and Details disclosure. --}}
    <div class="mcp-el mcp-audit-card my-3 w-full overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]" aria-hidden="true">
        <div class="group relative flex items-center gap-2.5 px-4 py-2.5">
            <span class="relative flex min-w-0 flex-1 items-center gap-2">
                <span class="chat-chip min-w-0" data-proposal-record-chip data-record-type="task">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['task'] }}"/>
                    </svg>
                    <span class="chat-chip-label">Schedule demo with Kovra Systems</span>
                </span>
                <span class="shrink-0 text-micro font-medium uppercase tracking-wider text-amber-600 dark:text-amber-400">Update</span>
                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400">
                    <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5"/>
                </span>
            </span>

            <span class="relative inline-flex shrink-0 items-center rounded-full bg-green-50 px-2 py-0.5 text-micro font-medium text-green-700 dark:bg-green-400/10 dark:text-green-400">
                Approved
            </span>

            <span class="relative inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-micro font-medium text-gray-400">
                <span>Details</span>
                <x-heroicon-o-chevron-down class="h-3 w-3"/>
            </span>
        </div>
    </div>

    {{-- Deciding a proposal resumes the turn (TurnContinuationService), so what
         lands under the decided row is the agent's OWN reply: flat prose like
         any other, with the record it touched rendered as a chip. --}}
    <div class="mcp-el mcp-approve-done w-full min-w-0 px-1 py-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100" aria-hidden="true">
        <span class="chat-chip" data-record-type="task">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['task'] }}"/>
            </svg>
            <span class="chat-chip-label">Schedule demo with Kovra Systems</span></span> has been marked done.
    </div>
</div>

{{-- ── Exchange 3: create a contact, also gated by review ──
     Creates are proposals too (see CreatePersonTool: "Propose creating a new
     person/contact. Returns a proposal for user approval."), so this exchange
     must NOT show a write landing unattended. It resolves into the same decided
     row + record card the real transcript renders. --}}
<div class="mcp-el mcp-user mcp-user-3 flex justify-end">
    <div class="max-w-[85%] rounded-2xl rounded-br-md bg-primary-50 px-4 py-2.5 text-sm leading-relaxed text-gray-900 ring-1 ring-inset ring-primary-600/10 dark:bg-primary-500/15 dark:text-gray-100 dark:ring-primary-400/15">
        Add Sarah Chen as a contact at <span class="chat-chip" data-record-type="company"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['company'] }}"/></svg><span class="chat-chip-label">Kovra Systems</span></span>. She's VP of Engineering.
    </div>
</div>

<div class="flex w-full flex-col items-start gap-3">
    <div class="mcp-el mcp-avatar mcp-avatar-3 w-full min-w-0">
        <span class="mcp-el mcp-label mcp-label-3 sr-only">Assistant</span>

        <div class="mcp-el mcp-text mcp-text-3 px-1 py-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100">
            Review the proposal below to add her to Kovra Systems.
        </div>
    </div>

    {{-- Decided create, same one-line shape as exchange 2 with the create tone. --}}
    <div class="mcp-el mcp-create-card my-3 w-full overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]" aria-hidden="true">
        <div class="group relative flex items-center gap-2.5 px-4 py-2.5">
            <span class="relative flex min-w-0 flex-1 items-center gap-2">
                <span class="chat-chip min-w-0" data-proposal-record-chip data-record-type="people">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['people'] }}"/>
                    </svg>
                    <span class="chat-chip-label">Sarah Chen</span>
                </span>
                <span class="shrink-0 text-micro font-medium uppercase tracking-wider text-blue-600 dark:text-blue-400">Create</span>
                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400">
                    <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5"/>
                </span>
            </span>

            <span class="relative inline-flex shrink-0 items-center rounded-full bg-green-50 px-2 py-0.5 text-micro font-medium text-green-700 dark:bg-green-400/10 dark:text-green-400">
                Approved
            </span>

            <span class="relative inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-micro font-medium text-gray-400">
                <span>Details</span>
                <x-heroicon-o-chevron-down class="h-3 w-3"/>
            </span>
        </div>
    </div>

    <div class="mcp-el mcp-create-done w-full min-w-0 px-1 py-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100" aria-hidden="true">
        <span class="chat-chip" data-record-type="people">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['people'] }}"/>
            </svg>
            <span class="chat-chip-label">Sarah Chen</span></span> has been created and linked to
        <span class="chat-chip" data-record-type="company">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['company'] }}"/>
            </svg>
            <span class="chat-chip-label">Kovra Systems</span></span>.
    </div>

    {{-- record_card block: header chip, then label/value field rows. No avatar
         circle -- that component was deleted; fields are the real shape. --}}
    <div class="mcp-el mcp-card my-3 w-full overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
            <span class="chat-chip min-w-0" data-record-title-chip data-record-type="people">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroChipIcons['people'] }}"/>
                </svg>
                <span class="chat-chip-label">Sarah Chen</span>
            </span>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ([
                ['label' => 'Job title', 'value' => 'VP of Engineering'],
                ['label' => 'Company', 'value' => 'Kovra Systems'],
            ] as $heroField)
                <div class="flex items-start gap-3 px-4 py-2.5">
                    <span class="w-24 shrink-0 text-micro font-medium leading-5 text-gray-400 dark:text-gray-500">{{ $heroField['label'] }}</span>
                    <span class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ $heroField['value'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
