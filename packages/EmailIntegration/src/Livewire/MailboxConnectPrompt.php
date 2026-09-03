<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Features\EmailIntegration;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectMailboxActions;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

/**
 * Home empty state prompting the user to connect a mailbox.
 *
 * @property-read Action $connectGmailAction
 * @property-read Action $connectAzureAction
 */
final class MailboxConnectPrompt extends Component implements HasActions, HasSchemas
{
    use HasConnectMailboxActions;
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function isVisible(): bool
    {
        if (! Feature::active(EmailIntegration::class)) {
            return false;
        }

        $user = auth()->user();
        $team = Filament::getTenant();

        if (! $user instanceof User) {
            return false;
        }

        return ! ConnectedAccount::hasActiveFor($user, $team instanceof Team ? $team : null);
    }

    public function render(): View
    {
        return view('email-integration::livewire.mailbox-connect-prompt');
    }
}
