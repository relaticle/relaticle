<?php

declare(strict_types=1);

namespace App\Support\Passport;

use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository as BaseClientRepository;

final class ClientRepository extends BaseClientRepository
{
    // oauth_clients.id is a uuid column, so Postgres answers a malformed client_id
    // with SQLSTATE 22P02: a 500 where the OAuth spec asks for invalid_client.
    public function find(string|int $id): ?Client
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return parent::find($id);
    }
}
