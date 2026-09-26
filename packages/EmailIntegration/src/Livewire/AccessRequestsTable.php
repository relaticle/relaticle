<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Relaticle\EmailIntegration\Livewire\Concerns\InteractsWithEmailAccessRequests;

final class AccessRequestsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithEmailAccessRequests;
    use InteractsWithSchemas;
    use InteractsWithTable {
        InteractsWithEmailAccessRequests::table insteadof InteractsWithTable;
    }

    public function render(): View
    {
        return view('email-integration::livewire.access-requests-table');
    }
}
