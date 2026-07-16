<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\User\UpdateNotificationPreferences;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class PostmarkWebhookController
{
    public function __construct(
        private UpdateNotificationPreferences $updatePreferences,
    ) {}

    public function __invoke(Request $request, string $secret): JsonResponse
    {
        $configured = config('services.postmark.webhook_secret');

        abort_unless(is_string($configured) && hash_equals($configured, $secret), 404);

        if ($request->input('RecordType') !== 'SubscriptionChange' || ! $request->boolean('SuppressSending')) {
            return response()->json(['ignored' => true]);
        }

        $email = strtolower((string) $request->input('Recipient'));

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            return response()->json(['ignored' => true]);
        }

        $this->updatePreferences->execute(
            $user,
            NotificationType::TaskDigest,
            NotificationChannel::Email,
            false,
        );

        return response()->json(['updated' => true]);
    }
}
