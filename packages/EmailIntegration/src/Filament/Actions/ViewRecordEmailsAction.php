<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

use App\Features\EmailIntegration;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final class ViewRecordEmailsAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'viewEmails';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->badgeColor('primary')
            ->visible(fn (): bool => Feature::active(EmailIntegration::class))
            ->badge(function (?Model $record): ?string {
                if (! $record instanceof Company && ! $record instanceof Opportunity && ! $record instanceof People) {
                    return null;
                }

                $user = auth()->user();

                if (! $user instanceof User) {
                    return null;
                }

                return resolve(EmailVisibilityService::class)->visibleEmailCountBadge($record, $user);
            });
    }
}
