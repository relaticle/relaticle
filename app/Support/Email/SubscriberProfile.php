<?php

declare(strict_types=1);

namespace App\Support\Email;

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

    public function hash(): string
    {
        return hash('sha256', json_encode(
            [$this->email, $this->firstName, $this->lastName, $this->tags],
            JSON_THROW_ON_ERROR,
        ));
    }
}
