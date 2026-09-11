<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class CalendarPushWebhookController
{
    public function __invoke(Request $request, string $provider): Response
    {
        if ($provider === EmailProvider::AZURE->value) {
            return $this->handleMicrosoft($request);
        }

        if ($provider === EmailProvider::GMAIL->value) {
            return $this->handleGoogle($request);
        }

        return response('', Response::HTTP_NOT_FOUND);
    }

    private function handleMicrosoft(Request $request): Response
    {
        $validationToken = $request->query('validationToken');

        if (is_string($validationToken) && $validationToken !== '') {
            return response($validationToken, Response::HTTP_OK)
                ->header('Content-Type', 'text/plain');
        }

        $notifications = $request->input('value');

        if (! is_array($notifications)) {
            return response('', Response::HTTP_ACCEPTED);
        }

        foreach ($notifications as $notification) {
            if (! is_array($notification)) {
                continue;
            }

            $subscriptionId = $notification['subscriptionId'] ?? null;
            $clientState = $notification['clientState'] ?? null;

            if (! is_string($subscriptionId) || $subscriptionId === '') {
                continue;
            }

            $account = ConnectedAccount::query()
                ->where('provider', EmailProvider::AZURE)
                ->where('calendar_push_channel_id', $subscriptionId)
                ->where('status', EmailAccountStatus::ACTIVE)
                ->first();

            if (! $account instanceof ConnectedAccount) {
                continue;
            }

            if (! $this->verificationTokenMatches($account, $clientState)) {
                continue;
            }

            dispatch(new IncrementalCalendarSyncJob($account));
        }

        return response('', Response::HTTP_ACCEPTED);
    }

    private function handleGoogle(Request $request): Response
    {
        $channelId = $request->header('X-Goog-Channel-ID');
        $resourceState = $request->header('X-Goog-Resource-State');
        $token = $request->header('X-Goog-Channel-Token');

        if (! is_string($channelId) || $channelId === '') {
            return response('', Response::HTTP_BAD_REQUEST);
        }

        $account = ConnectedAccount::query()
            ->where('provider', EmailProvider::GMAIL)
            ->where('calendar_push_channel_id', $channelId)
            ->where('status', EmailAccountStatus::ACTIVE)
            ->first();

        if (! $account instanceof ConnectedAccount) {
            return response('', Response::HTTP_NOT_FOUND);
        }

        if (! $this->verificationTokenMatches($account, $token)) {
            return response('', Response::HTTP_FORBIDDEN);
        }

        if ($resourceState === 'sync') {
            return response('', Response::HTTP_OK);
        }

        if (in_array($resourceState, ['exists', 'not_exists'], true)) {
            dispatch(new IncrementalCalendarSyncJob($account));
        }

        return response('', Response::HTTP_OK);
    }

    private function verificationTokenMatches(ConnectedAccount $account, mixed $token): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        return hash_equals((string) $account->calendar_push_verification_token, $token);
    }
}
