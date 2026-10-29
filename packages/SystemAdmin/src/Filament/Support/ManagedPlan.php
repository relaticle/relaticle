<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use App\Enums\Plan;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Actions\StartManagedPlan;
use Relaticle\SystemAdmin\Actions\WithdrawManagedInvoice;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

final class ManagedPlan
{
    public static function startAction(): Action
    {
        return Action::make('startManagedPlan')
            ->label('Start managed plan')
            ->icon('heroicon-o-document-currency-dollar')
            ->authorize('manageManagedPlan')
            ->modalHeading('Start a managed plan?')
            ->modalDescription('Stripe emails a yearly invoice. The plan switches on when it is paid.')
            ->modalSubmitActionLabel('Send invoice')
            ->schema([
                Select::make('plan')
                    ->options(self::planOptions())
                    ->default(Plan::Concierge->value)
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set): mixed => $set('amount', Plan::tryFrom((string) $state)?->defaultYearlyPrice()))
                    ->required(),
                TextInput::make('amount')
                    ->label('Yearly amount (USD)')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->prefix('$')
                    ->default(Plan::Concierge->defaultYearlyPrice())
                    ->required(),
                TextInput::make('amount_confirmation')
                    ->label('Type the yearly amount again')
                    ->helperText('Stripe emails the invoice to the customer as soon as you send it.')
                    ->numeric()
                    ->integer()
                    ->prefix('$')
                    ->required()
                    ->same('amount')
                    ->validationMessages(['same' => 'The two amounts differ. Check the yearly amount before Stripe sends the invoice.'])
                    ->dehydrated(false),
                TextInput::make('billing_email')
                    ->label('Billing email')
                    ->helperText('Optional. Receives the invoice link too. The owner stays the Stripe customer.')
                    ->email(),
            ])
            ->action(function (array $data, Workspace $record): void {
                try {
                    resolve(StartManagedPlan::class)->execute(
                        self::administrator(),
                        $record,
                        Plan::from($data['plan']),
                        (int) $data['amount'],
                        $data['billing_email'] ?: null,
                    );
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), 'mountedActions.0.data.'));
                } catch (ApiErrorException $exception) {
                    self::reportStripeFailure(
                        $exception,
                        "Starting a managed plan failed for workspace {$record->getKey()}",
                        'Invoice not sent',
                        'Stripe did not complete the request. Reload the page before trying again: the subscription may already exist.',
                    );

                    return;
                }

                Notification::make()->title('Invoice sent')->success()->send();
            });
    }

    public static function withdrawAction(): Action
    {
        return Action::make('withdrawManagedInvoice')
            ->label('Withdraw managed invoice')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize('manageManagedPlan')
            ->visible(fn (Workspace $record): bool => $record->pendingManagedSubscription() instanceof Subscription)
            ->requiresConfirmation()
            ->modalHeading('Withdraw the managed invoice?')
            ->modalDescription("Voids the unpaid invoice and cancels the subscription. The workspace's plan does not change.")
            ->modalSubmitActionLabel('Withdraw invoice')
            ->action(function (Workspace $record): void {
                try {
                    resolve(WithdrawManagedInvoice::class)->execute(self::administrator(), $record);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Invoice not withdrawn')
                        ->body(Arr::first(Arr::flatten($exception->errors())))
                        ->send();

                    return;
                } catch (ApiErrorException $exception) {
                    self::reportStripeFailure(
                        $exception,
                        "Managed invoice withdrawal failed for subscription {$record->pendingManagedSubscription()?->stripe_id} in workspace {$record->getKey()}",
                        'Invoice not withdrawn',
                        'Stripe did not complete the request. Reload the page: the invoice may have been paid or the request may need another try.',
                    );

                    return;
                }

                Notification::make()->title('Invoice withdrawn')->success()->send();
            });
    }

    private static function administrator(): SystemAdministrator
    {
        $administrator = auth('sysadmin')->user();

        abort_unless($administrator instanceof SystemAdministrator, 403);

        return $administrator;
    }

    private static function reportStripeFailure(ApiErrorException $exception, string $context, string $title, string $body): void
    {
        report(new RuntimeException("{$context}: {$exception->getMessage()}", 0, $exception));

        Notification::make()->danger()->title($title)->body($body)->send();
    }

    /** @return array<string, string> */
    private static function planOptions(): array
    {
        return collect(Plan::cases())
            ->filter(fn (Plan $plan): bool => $plan->isManaged())
            ->mapWithKeys(fn (Plan $plan): array => [$plan->value => $plan->marketingLabel()])
            ->all();
    }
}
