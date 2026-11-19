@php
    use Filament\Tables\Table;
    use Illuminate\Contracts\Pagination\CursorPaginator;
    use Illuminate\Contracts\Pagination\Paginator;
@endphp

@if ($table->isPaginated() && ($records instanceof Paginator || $records instanceof CursorPaginator))
    <td class="fi-ta-pagination-footer" x-data="tablePaginationFooter">
        <x-filament::pagination
            :extreme-links="$table->hasExtremePaginationLinks()"
            :loading-targets="Table::LOADING_TARGETS"
            :page-options="$table->getPaginationPageOptions()"
            :paginator="$records"
        />
    </td>
@endif
