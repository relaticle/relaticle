<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Exception;
use Relaticle\EmailIntegration\Data;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;

final readonly class GoogleCalendarService implements CalendarServiceInterface
{
    private function __construct(
        private ConnectedAccount $account,
        private Calendar $client,
    ) {}

    public static function forAccount(ConnectedAccount $account): self
    {
        $google = new GoogleClient;
        $google->setClientId((string) config('services.gmail.client_id'));
        $google->setClientSecret((string) config('services.gmail.client_secret'));

        // `expires_in` must reflect seconds remaining from `created` (= now). If the stored token
        // has already lapsed we pass 0 so `isAccessTokenExpired()` fires and refresh kicks in.
        $secondsUntilExpiry = $account->token_expires_at !== null && $account->token_expires_at->isFuture()
            ? (int) abs(now()->diffInSeconds($account->token_expires_at))
            : 0;

        $google->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $secondsUntilExpiry,
            'created' => now()->timestamp,
        ]);

        if ($google->isAccessTokenExpired() && $account->refresh_token) {
            $google->fetchAccessTokenWithRefreshToken($account->refresh_token);

            $token = $google->getAccessToken();
            $account->update([
                'access_token' => $token['access_token'] ?? $account->access_token,
                'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            ]);
        }

        return new self($account, new Calendar($google));
    }

    public function account(): ConnectedAccount
    {
        return $this->account;
    }

    public function client(): Calendar
    {
        return $this->client;
    }

    public function initialSync(): Data\CalendarSyncResult
    {
        $events = [];
        $pageToken = null;
        $nextSyncToken = null;

        do {
            $params = [
                'timeMin' => now()->subDays(90)->toRfc3339String(),
                'singleEvents' => true,
                'showDeleted' => false,
                'orderBy' => 'startTime',
                'maxResults' => 250,
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->client->events->listEvents('primary', $params);

            foreach ($response->getItems() as $event) {
                $events[] = $event;
            }

            $pageToken = $response->getNextPageToken();
            $nextSyncToken = $response->getNextSyncToken() ?: $nextSyncToken;
        } while ($pageToken !== null);

        return new Data\CalendarSyncResult(events: $events, nextSyncToken: $nextSyncToken);
    }

    /**
     * @throws Exceptions\CalendarSyncTokenExpired when Google invalidates the syncToken (HTTP 410)
     */
    public function fetchDelta(string $syncToken): Data\CalendarSyncResult
    {
        $events = [];
        $pageToken = null;
        $nextSyncToken = null;

        do {
            $params = [
                'syncToken' => $syncToken,
                'singleEvents' => true,
                'maxResults' => 250,
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
                unset($params['syncToken']);
            }

            try {
                $response = $this->client->events->listEvents('primary', $params);
            } catch (Exception $e) {
                if ($e->getCode() === 410) {
                    throw Exceptions\CalendarSyncTokenExpired::forAccount($this->account->getKey());
                }
                throw $e;
            }

            foreach ($response->getItems() as $event) {
                $events[] = $event;
            }

            $pageToken = $response->getNextPageToken();
            $nextSyncToken = $response->getNextSyncToken() ?: $nextSyncToken;
        } while ($pageToken !== null);

        return new Data\CalendarSyncResult(events: $events, nextSyncToken: $nextSyncToken);
    }
}
