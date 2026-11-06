@php
    $note = $getRecord();
    $chips = $getLinkedRecordChips();
    $excerpt = $getExcerpt();
    $creator = $getCreatorChip();
    $title = filled($note->title) ? $note->title : __('filament/resources/note.cards.untitled');
@endphp

<article @class(['fi-note-card', 'fi-note-card-deleted' => $note->trashed()])>
    @if ($chips !== [] || $note->trashed())
        <div class="fi-note-card-records">
            @if ($chips !== [])
                {{ $chips[0] }}
            @endif

            @if (count($chips) > 1)
                <span class="fi-note-card-more">+{{ count($chips) - 1 }}</span>
            @endif

            @if ($note->trashed())
                <span class="fi-note-card-deleted-badge">{{ __('filament/resources/note.cards.deleted') }}</span>
            @endif
        </div>
    @endif

    <h3 class="fi-note-card-title" title="{{ $title }}">
        {{ $title }}
    </h3>

    <p @class(['fi-note-card-excerpt', 'fi-note-card-excerpt-empty' => blank($excerpt)])>
        {{ filled($excerpt) ? $excerpt : __('filament/resources/note.cards.no_content') }}
    </p>

    <footer class="fi-note-card-footer">
        <span class="fi-note-card-creator">
            {{ $creator ?? $note->created_by }}
        </span>

        <time datetime="{{ $note->created_at?->toIso8601String() }}" class="fi-note-card-date">
            {{ $getCreatedLabel() }}
        </time>
    </footer>
</article>
