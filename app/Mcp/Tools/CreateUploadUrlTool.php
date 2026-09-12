<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\UploadException;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Mcp\Tools\Concerns\LimitsUploads;
use App\Models\Team;
use App\Models\User;
use App\Support\Media\TemporaryUploads;
use App\Support\Media\UploadAllowlist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\URL;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('create-upload-url')]
#[Title('Create Upload URL')]
#[Description('Get a short-lived signed URL to PUT a file body to (max 10 MB, pdf/doc/docx/jpeg/png/gif/webp). Then call upload-file with the returned upload_id to finish. Use upload-file directly with source_url or base64 when you can.')]
final class CreateUploadUrlTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;
    use LimitsUploads;

    private const int EXPIRY_MINUTES = 5;

    protected function destructiveHint(): bool
    {
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filename' => $schema->string()->required()->description('The file name with its extension, e.g. "brief.pdf". Allowed extensions: '.implode(', ', UploadAllowlist::extensions()).'.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string()->required(),
            'url' => $schema->string()->required(),
            'headers' => $schema->object()->required(),
            'expires_at' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('create')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate(['filename' => ['required', 'string', 'max:255']]);

        /** @var Team $team */
        $team = $user->currentTeam;

        if (($limited = $this->denyIfUploadLimitReached($team)) instanceof Response) {
            return $limited;
        }

        try {
            $uploadId = TemporaryUploads::newName((string) $validated['filename']);
        } catch (UploadException $exception) {
            return Response::error($exception->getMessage());
        }

        $expiresAt = now()->addMinutes(self::EXPIRY_MINUTES);

        return Response::structured([
            'upload_id' => $uploadId,
            'url' => URL::temporarySignedRoute('mcp.uploads.receive', $expiresAt, ['upload' => $uploadId]),
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }
}
