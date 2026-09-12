<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Upload\StoreAgentUpload;
use App\Exceptions\UploadException;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Mcp\Tools\Concerns\LimitsUploads;
use App\Models\Team;
use App\Models\User;
use App\Support\Media\UploadAllowlist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Title('Upload File')]
#[Description('Store a file in this workspace and get back the path to set on a file-upload custom field. Pass exactly one of: source_url (public https), base64 with filename (max 5 MB decoded), or upload_id from create-upload-url. Allowed types: pdf, doc, docx, jpeg, png, gif, webp; 10 MB max.')]
final class UploadFileTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;
    use LimitsUploads;

    protected function destructiveHint(): bool
    {
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'source_url' => $schema->string()->description('A public https URL to fetch. No redirects are followed.'),
            'base64' => $schema->string()->description('The file body, base64 encoded. Requires filename.'),
            'filename' => $schema->string()->description('The original file name, used with base64.'),
            'upload_id' => $schema->string()->description('The upload_id from create-upload-url after the PUT succeeded.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'file_id' => $schema->string()->required(),
            'path' => $schema->string()->required(),
            'url' => $schema->string()->required(),
            'mime_type' => $schema->string()->required(),
            'size' => $schema->integer()->required(),
            'suggested_markdown' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('create')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'source_url' => ['nullable', 'string', 'url:https', 'max:2048', 'required_without_all:base64,upload_id', 'prohibits:base64,upload_id'],
            'base64' => ['nullable', 'string', 'required_with:filename', 'prohibits:upload_id'],
            'filename' => ['nullable', 'string', 'max:255', 'required_with:base64'],
            'upload_id' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var Team $team */
        $team = $user->currentTeam;

        if (($limited = $this->denyIfUploadLimitReached($team)) instanceof Response) {
            return $limited;
        }

        /** @var array{source_url?: ?string, base64?: ?string, filename?: ?string, upload_id?: ?string} $validated */
        try {
            $media = resolve(StoreAgentUpload::class)->execute($user, $team, $validated);
        } catch (UploadException $exception) {
            return Response::error($exception->getMessage());
        }

        $name = (string) $media->getCustomProperty('original_name', $media->file_name);
        $url = $media->getUrl();

        return Response::structured([
            'file_id' => $media->uuid,
            'path' => $media->getPathRelativeToRoot(),
            'url' => $url,
            'mime_type' => (string) $media->mime_type,
            'size' => (int) $media->size,
            'suggested_markdown' => UploadAllowlist::isImage((string) $media->mime_type) ? "![{$name}]({$url})" : "[{$name}]({$url})",
        ]);
    }
}
