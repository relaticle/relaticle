@php
    use Filament\Tables\Table;
    use Illuminate\Contracts\Pagination\CursorPaginator;
    use Illuminate\Contracts\Pagination\Paginator;
@endphp

@if ($records instanceof Paginator || $records instanceof CursorPaginator)
    <td class="fi-ta-pagination-footer" wire:ignore.self x-data="tablePaginationFooter">
        <x-filament::pagination
            :extreme-links="$table->hasExtremePaginationLinks()"
            :loading-targets="Table::LOADING_TARGETS"
            :page-options="$table->getPaginationPageOptions()"
            :paginator="$records"
        />
    </td>
@endif
