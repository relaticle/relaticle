<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;

final class InitialSyncPageStoreBatch
{
    /**
     * @param  list<string>  $pageMessageIds
     * @param  list<string>  $messageIdsToStore
     * @param  Closure(ConnectedAccount): void  $onPageStored
     */
    public static function dispatchEmails(
        ConnectedAccount $account,
        array $pageMessageIds,
        array $messageIdsToStore,
        ?string $historyCursor,
        ?string $nextPageToken,
        ?string $pageCursor,
        Closure $onPageStored,
        int $storeAttempt = 1,
    ): void {
        if ($messageIdsToStore === []) {
            return;
        }

        $accountId = (string) $account->getKey();

        $jobs = collect($messageIdsToStore)
            ->chunk(Config::integer('email-integration.sync.batch_size', 50))
            ->flatMap(fn (Collection $chunk): array => $chunk
                ->map(fn (string $id): StoreEmailJob => new StoreEmailJob($account, $id))
                ->all())
            ->all();

        Bus::batch($jobs)
            ->name("Initial sync: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->finally(static function (Batch $batch) use (
                $accountId,
                $pageMessageIds,
                $historyCursor,
                $nextPageToken,
                $pageCursor,
                $onPageStored,
                $storeAttempt,
            ): void {
                $account = ConnectedAccount::query()->whereKey($accountId)->first();

                if (! $account instanceof ConnectedAccount) {
                    return;
                }

                $missingIds = self::missingMessageIds($account, $pageMessageIds);

                if ($missingIds !== []) {
                    if ($storeAttempt < self::maxStoreAttempts()) {
                        self::dispatchEmails(
                            $account,
                            $pageMessageIds,
                            $missingIds,
                            $historyCursor,
                            $nextPageToken,
                            $pageCursor,
                            $onPageStored,
                            $storeAttempt + 1,
                        );

                        return;
                    }

                    self::markImportStoreFailed($account, count($missingIds), 'message(s)');

                    return;
                }

                $onPageStored($account);
            })
            ->dispatch();
    }

    /**
     * @param  list<CalendarEventData>  $pageEvents
     * @param  list<CalendarEventData>  $eventsToStore
     * @param  Closure(ConnectedAccount): void  $onPageStored
     */
    public static function dispatchMeetings(
        ConnectedAccount $account,
        array $pageEvents,
        array $eventsToStore,
        Closure $onPageStored,
        int $storeAttempt = 1,
    ): void {
        if ($eventsToStore === []) {
            return;
        }

        $accountId = (string) $account->getKey();

        $jobs = array_map(
            fn (CalendarEventData $event): StoreMeetingJob => new StoreMeetingJob($account, $event),
            $eventsToStore,
        );

        Bus::batch($jobs)
            ->name("Initial calendar sync: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->finally(static function (Batch $batch) use (
                $accountId,
                $pageEvents,
                $onPageStored,
                $storeAttempt,
            ): void {
                $account = ConnectedAccount::query()->whereKey($accountId)->first();

                if (! $account instanceof ConnectedAccount) {
                    return;
                }

                $missingEvents = self::missingMeetingEvents($account, $pageEvents);

                if ($missingEvents !== []) {
                    if ($storeAttempt < self::maxStoreAttempts()) {
                        self::dispatchMeetings(
                            $account,
                            $pageEvents,
                            $missingEvents,
                            $onPageStored,
                            $storeAttempt + 1,
                        );

                        return;
                    }

                    self::markImportStoreFailed($account, count($missingEvents), 'event(s)');

                    return;
                }

                $onPageStored($account);
            })
            ->dispatch();
    }

    /**
     * @param  list<string>  $pageMessageIds
     * @return list<string>
     */
    private static function missingMessageIds(ConnectedAccount $account, array $pageMessageIds): array
    {
        if ($pageMessageIds === []) {
            return [];
        }

        $storedIds = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->whereIn('provider_message_id', $pageMessageIds)
            ->pluck('provider_message_id')
            ->all();

        return array_values(array_diff($pageMessageIds, $storedIds));
    }

    /**
     * @param  list<CalendarEventData>  $pageEvents
     * @return list<CalendarEventData>
     */
    private static function missingMeetingEvents(ConnectedAccount $account, array $pageEvents): array
    {
        if ($pageEvents === []) {
            return [];
        }

        $pageIds = array_map(
            fn (CalendarEventData $event): string => $event->providerEventId,
            $pageEvents,
        );

        $storedIds = Meeting::query()
            ->where('connected_account_id', $account->getKey())
            ->whereIn('provider_event_id', $pageIds)
            ->pluck('provider_event_id')
            ->all();

        return array_values(array_filter(
            $pageEvents,
            fn (CalendarEventData $event): bool => ! in_array($event->providerEventId, $storedIds, true),
        ));
    }

    private static function maxStoreAttempts(): int
    {
        return max(1, Config::integer('email-integration.sync.initial_store_attempts', 3));
    }

    private static function markImportStoreFailed(ConnectedAccount $account, int $missingCount, string $resourceLabel): void
    {
        $account->update([
            'status' => EmailAccountStatus::ERROR,
            'last_error' => "Initial import paused: {$missingCount} {$resourceLabel} could not be stored after "
                .self::maxStoreAttempts().' attempts.',
        ]);
    }
}
