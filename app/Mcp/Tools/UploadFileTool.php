<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Upload\StoreAgentUpload;
use App\Exceptions\UploadException;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\TemporaryUploads;
use App\Support\Media\UploadAllowlist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('upload-file')]
#[Title('Upload File')]
#[Description('Store a file in this workspace. Returns suggested_markdown to embed the file in a rich-editor field (note body, task description), and file_id to refer to the stored file. Pass exactly one of: source_url (public https), base64 with filename, or upload_id from create-upload-url. filename is optional with upload_id but recommended. Allowed types: pdf, doc, docx, xlsx, pptx, jpg, jpeg, png, gif, webp; 10 MB max. Limited to 60 calls per hour per workspace, refused files included.')]
final class UploadFileTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;

    private const int UPLOADS_PER_HOUR = 60;

    protected function destructiveHint(): bool
    {
        return false;
    }

    protected function openWorldHint(): bool
    {
        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'source_url' => $schema->string()->description('A public https URL to fetch. No redirects are followed.'),
            'base64' => $schema->string()->description('The file body, base64 encoded. Requires filename.'),
            'filename' => $schema->string()->description('The original file name. Required with base64. Optional with upload_id, where the name is otherwise rebuilt from the id, losing capitals and spaces.'),
            'upload_id' => $schema->string()->description('The upload_id from create-upload-url after the PUT succeeded.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'file_id' => $schema->string()->required()->description('The stored file id.'),
            'name' => $schema->string()->required(),
            'url' => $schema->string()->required()->description('A link to view the file now. It expires after 30 minutes; do not store it.'),
            'mime_type' => $schema->string()->required(),
            'size' => $schema->integer()->required(),
            'suggested_markdown' => $schema->string()->required()->description('Stable markdown to paste into a rich-editor field.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('create')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var Workspace $workspace */
        $workspace = $user->currentWorkspace;

        if (RateLimiter::increment("mcp-uploads:{$workspace->getKey()}", 3600) > self::UPLOADS_PER_HOUR) {
            return Response::error(__('uploads.errors.rate_limited'));
        }

        $validated = $request->validate([
            'source_url' => ['nullable', 'string', 'url:https', 'max:2048', 'required_without_all:base64,upload_id', 'prohibits:base64,upload_id'],
            'base64' => ['nullable', 'string', 'prohibits:upload_id'],
            'filename' => ['nullable', 'string', 'max:255', 'required_with:base64'],
            'upload_id' => ['nullable', 'string', 'max:'.TemporaryUploads::MAX_NAME_LENGTH],
        ]);

        /** @var array{source_url?: ?string, base64?: ?string, filename?: ?string, upload_id?: ?string} $validated */
        try {
            $media = resolve(StoreAgentUpload::class)->execute($user, $workspace, $validated);
        } catch (UploadException $exception) {
            return Response::error($exception->getMessage());
        }

        $label = $this->markdownLabel($media->name);
        $stableUrl = route('media.show', ['media' => $media->uuid]);

        return Response::structured([
            'file_id' => $media->uuid,
            'name' => $media->name,
            'url' => $media->getUrl(),
            'mime_type' => (string) $media->mime_type,
            'size' => (int) $media->size,
            'suggested_markdown' => UploadAllowlist::isImage((string) $media->mime_type) ? "![{$label}]({$stableUrl})" : "[{$label}]({$stableUrl})",
        ]);
    }

    private function markdownLabel(string $name): string
    {
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return str_replace(['[', ']', '(', ')'], ['\[', '\]', '\(', '\)'], $name);
    }
}
