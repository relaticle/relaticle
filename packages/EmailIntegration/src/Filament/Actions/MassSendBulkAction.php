<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

use App\Features\EmailIntegration;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\OpenMassSendComposer;

final class MassSendBulkAction extends BulkAction
{
    public static function make(?string $name = null): static
    {
        /** @var static $action */
        $action = parent::make($name ?? 'massSend');

        return $action->setupMassSendAction(forCompanies: false);
    }

    public static function forCompanies(?string $name = null): static
    {
        /** @var static $action */
        $action = parent::make($name ?? 'massSend');

        return $action->setupMassSendAction(forCompanies: true);
    }

    private function setupMassSendAction(bool $forCompanies): static
    {
        return $this
            ->label(__('filament/actions/mass-send.label'))
            ->icon('heroicon-o-paper-airplane')
            ->visible(function (): bool {
                /** @var User|null $user */
                $user = auth()->user();
                /** @var Team|null $team */
                $team = filament()->getTenant();

                return Feature::active(EmailIntegration::class)
                    && $user instanceof User
                    && ConnectedAccount::hasSendableFor($user, $team);
            })
            ->action(function (Collection $records) use ($forCompanies): void {
                /** @var Component $page */
                $page = $this->getLivewire();

                resolve(OpenMassSendComposer::class)->execute($page, $records, $forCompanies);
            });
    }
}
