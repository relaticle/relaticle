<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

final readonly class AgentConnection
{
    public function __construct(
        public string $name,
        public ?string $oauthClientId = null,
        public ?int $personalAccessTokenId = null,
    ) {}

    /** @return array{oauth_client_id: ?string, personal_access_token_id: ?int} */
    public function credential(): array
    {
        return [
            'oauth_client_id' => $this->oauthClientId,
            'personal_access_token_id' => $this->personalAccessTokenId,
        ];
    }
}
