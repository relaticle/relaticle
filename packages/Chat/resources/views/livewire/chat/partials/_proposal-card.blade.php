{{-- Transcript audit card for a single proposal (a plan of one).

     Two render modes, both gated by the transcript x-for in chat-interface.blade.php:
       1. status === 'pending' (a partially-resolved batch still docked at the
          composer): COMPACT progress view — only the resolved per-item chips plus
          a muted "N of M resolved" hint. The full editor lives in the dock, so we
          deliberately omit the header, fields, and final badge to avoid a
          confusing duplicate.
       2. status !== 'pending' (finalized / single resolved): the full read-only
          audit card.

     A multi-step plan wraps the same body in one shared card: see
     _proposal-plan-card.blade.php.

     Surface: the solid data-block tier (crisp hairline card, no shadow),
     matching the docked card and the read-result blocks. --}}
<div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
    @include('chat::livewire.chat.partials._proposal-card-body')
</div>
