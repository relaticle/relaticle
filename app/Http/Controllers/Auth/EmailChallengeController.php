<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RequestEmailChallenge;
use App\Http\Controllers\Auth\Concerns\HandlesEmailChallengeRequests;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class EmailChallengeController
{
    use HandlesEmailChallengeRequests;

    public function __construct(private RequestEmailChallenge $requestEmailChallenge) {}

    public function store(Request $request): JsonResponse
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
