<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

/**
 * Reports how many records the list holds in the table header, beside the
 * resource actions. Filament prints the total only in the pagination footer,
 * which sits below the last row and out of sight on a long list.
 *
 * The description is attached when the table resolves rather than in table(),
 * because the custom fields trait already owns that method.
 */
trait HasRecordCountDescription
{
    public function getTable(): Table
    {
        return parent::getTable()->description(function (): HtmlString {
            $count = $this->getAllTableRecordsCount();

            $label = trans_choice('filament/pages/list.record_count', $count, [
                'count' => Number::format($count),
            ]);

            return new HtmlString(view('filament.app.record-count', ['label' => $label])->render());
        });
    }
}
