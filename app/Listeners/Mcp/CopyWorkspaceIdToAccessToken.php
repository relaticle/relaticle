<?php

declare(strict_types=1);

namespace App\Listeners\Mcp;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptTrait;

// The token endpoint has no session, so the workspace is traced through the grant's own
// encrypted payload: the auth code it redeems, or the access token its refresh replaces.
final class CopyWorkspaceIdToAccessToken
{
    use CryptTrait;

    public function __construct(
        private readonly Request $request,
        Encrypter $encrypter,
    ) {
        $this->setEncryptionKey(Passport::tokenEncryptionKey($encrypter));
    }

    public function handle(AccessTokenCreated $event): void
    {
        if ($event->userId === null) {
            return;
        }

        $workspaceId = match ($this->request->input('grant_type')) {
            'authorization_code' => $this->workspaceIdOf('oauth_auth_codes', $this->grantedId('code', 'auth_code_id'), $event),
            'refresh_token' => $this->workspaceIdOf('oauth_access_tokens', $this->grantedId('refresh_token', 'access_token_id'), $event),
            default => null,
        };

        if ($workspaceId === null) {
            return;
        }

        DB::table('oauth_access_tokens')
            ->where('id', $event->tokenId)
            ->update(['workspace_id' => $workspaceId]);
    }

    private function grantedId(string $parameter, string $key): ?string
    {
        $encrypted = $this->request->string($parameter)->value();

        if ($encrypted === '') {
            return null;
        }

        $id = data_get(json_decode($this->decrypt($encrypted), true), $key);

        return is_string($id) ? $id : null;
    }

    private function workspaceIdOf(string $table, ?string $id, AccessTokenCreated $event): ?string
    {
        if ($id === null) {
            return null;
        }

        $workspaceId = DB::table($table)
            ->where('id', $id)
            ->where('user_id', $event->userId)
            ->where('client_id', $event->clientId)
            ->value('workspace_id');

        return is_string($workspaceId) ? $workspaceId : null;
    }
}
