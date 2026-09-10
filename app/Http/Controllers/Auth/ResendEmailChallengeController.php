<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RequestEmailChallenge;
use App\Http\Controllers\Auth\Concerns\HandlesEmailChallengeRequests;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Its own class, not a second EmailChallengeController method: "resend" is
 * not an allowed preset controller method name.
 */
final readonly class ResendEmailChallengeController
{
    use HandlesEmailChallengeRequests;

    public function __construct(private RequestEmailChallenge $requestEmailChallenge) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        $challenge = $this->requestEmailChallenge->execute(
            $data['purpose'],
            $data['email'],
            $user instanceof User ? $user : null,
            $data['operation_id'],
            (string) $request->ip(),
        );

        return $this->challengeAccepted($challenge);
    }
}
