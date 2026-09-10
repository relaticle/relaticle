<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Enums\EmailChallengePurpose;
use App\Models\EmailChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared by every email-challenge route: request validation and a response
 * that never leaks account state.
 */
trait HandlesEmailChallengeRequests
{
    /**
     * Validates the request shape before any value reaches the action, so
     * an array-typed field 422s here instead of raising a PHP warning later.
     *
     * @return array{purpose: EmailChallengePurpose, email: string, operation_id: ?string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', Rule::enum(EmailChallengePurpose::class)],
            'email' => ['nullable', 'string', 'email:rfc'],
            'operation_id' => ['nullable', 'string', 'ulid'],
        ]);

        return [
            'purpose' => EmailChallengePurpose::from($data['purpose']),
            'email' => $data['email'] ?? '',
            'operation_id' => $data['operation_id'] ?? null,
        ];
    }

    /**
     * The response carries only what the client needs to poll and resend.
     * No email, no indication of whether the address has an account.
     */
    private function challengeAccepted(EmailChallenge $challenge): JsonResponse
    {
        return response()->json([
            'challenge_id' => $challenge->flow_id,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_available_at' => now()
                ->addSeconds((int) config('auth.email_codes.cooldown_seconds', 60))
                ->toIso8601String(),
        ], Response::HTTP_ACCEPTED);
    }
}
