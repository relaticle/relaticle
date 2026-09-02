<?php

declare(strict_types=1);

namespace App\Support\Email;

use App\Models\User;

final readonly class SubscriberProfile
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public array $tags,
    ) {}

    /**
     * Whether the user already carries this exact profile in Mailcoach. The
     * uuid half matters: a matching hash with no uuid means the subscriber was
     * never created, so the sync still has work to do.
     */
    public function matchesStored(User $user): bool
    {
        return $user->mailcoach_subscriber_uuid !== null
            && $user->subscriber_profile_hash === $this->hash();
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(
            [$this->email, $this->firstName, $this->lastName, $this->tags],
            JSON_THROW_ON_ERROR,
        ));
    }
}
