<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Symfony\Component\Mime\MimeTypes;

final readonly class EmailInlineImageEmbedder
{
    /**
     * Turn RichEditor embedded images into CID inline attachments the reader can
     * serve through {@see EmailHtmlSanitizer}. Composer images are stored on disk
     * with a `data-id` path while the HTML still references a transient URL.
     *
     * Image `src` and `data-id` are client-controlled, so disk files are copied
     * only when they live in the sender's tenant compose directory and are images.
     *
     * @param  array<int, string>  $attachmentPaths
     * @param  array<string, string>  $attachmentFileNames
     * @param  array<string, array{is_inline?: bool, content_id?: ?string}>  $attachmentAttributes
     * @return array{
     *     body_html: string,
     *     attachments: array<int, string>,
     *     attachment_file_names: array<string, string>,
     *     attachment_attributes: array<string, array{is_inline?: bool, content_id?: ?string}>,
     * }
     */
    public function embed(
        User $user,
        string $bodyHtml,
        array $attachmentPaths = [],
        array $attachmentFileNames = [],
        array $attachmentAttributes = [],
    ): array {
        if (! str_contains($bodyHtml, '<img')) {
            return [
                'body_html' => $bodyHtml,
                'attachments' => $attachmentPaths,
                'attachment_file_names' => $attachmentFileNames,
                'attachment_attributes' => $attachmentAttributes,
            ];
        }

        $document = $this->loadDocument($bodyHtml);
        $images = $document->getElementsByTagName('img');

        if ($images->length === 0) {
            return [
                'body_html' => $bodyHtml,
                'attachments' => $attachmentPaths,
                'attachment_file_names' => $attachmentFileNames,
                'attachment_attributes' => $attachmentAttributes,
            ];
        }

        /** @var array<string, string> $contentIdsBySourcePath */
        $contentIdsBySourcePath = [];

        for ($index = $images->length - 1; $index >= 0; $index--) {
            $image = $images->item($index);

            if (! $image instanceof DOMElement) {
                continue;
            }

            $src = trim($image->getAttribute('src'));

            if ($src !== '' && str_starts_with(mb_strtolower($src), 'cid:')) {
                continue;
            }

            $sourcePath = $this->resolveSourcePath($image, $src);

            if ($sourcePath === null) {
                continue;
            }

            if (! isset($contentIdsBySourcePath[$sourcePath])) {
                $embedded = $this->copyToEmailAttachmentDisk($user, $sourcePath);

                if ($embedded === null) {
                    continue;
                }

                $contentIdsBySourcePath[$sourcePath] = $embedded['content_id'];
                $attachmentPaths[] = $embedded['path'];
                $attachmentFileNames[$embedded['path']] = $embedded['filename'];
                $attachmentAttributes[$embedded['path']] = [
                    'is_inline' => true,
                    'content_id' => $embedded['content_id'],
                ];
            }

            $image->setAttribute('src', 'cid:'.$contentIdsBySourcePath[$sourcePath]);
            $image->removeAttribute('data-id');
        }

        return [
            'body_html' => $this->serializeDocument($document),
            'attachments' => $attachmentPaths,
            'attachment_file_names' => $attachmentFileNames,
            'attachment_attributes' => $attachmentAttributes,
        ];
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="email-inline-image-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function serializeDocument(DOMDocument $document): string
    {
        $root = $document->getElementById('email-inline-image-root');

        if (! $root instanceof DOMElement) {
            return $document->saveHTML() ?: '';
        }

        $html = '';

        foreach ($root->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    private function resolveSourcePath(DOMElement $image, string $src): ?string
    {
        $dataId = trim($image->getAttribute('data-id'));

        if ($dataId !== '') {
            return $dataId;
        }

        if ($src === '') {
            return null;
        }

        if (str_starts_with(mb_strtolower($src), 'data:image/')) {
            return $src;
        }

        return null;
    }

    /**
     * @return array{path: string, filename: string, content_id: string}|null
     */
    private function copyToEmailAttachmentDisk(User $user, string $sourcePath): ?array
    {
        if (str_starts_with(mb_strtolower($sourcePath), 'data:image/')) {
            return $this->copyDataUri($sourcePath);
        }

        $path = $this->authorizedStoragePath($user, $sourcePath);

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk(EmailAttachment::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $mimeType = $disk->mimeType($path);

        if (! is_string($mimeType) || ! str_starts_with(mb_strtolower($mimeType), 'image/')) {
            return null;
        }

        $extension = $this->extensionFromMimeType($mimeType);
        $destination = 'email-attachments/'.Str::ulid().'.'.$extension;

        Storage::disk(EmailAttachment::DISK)->put($destination, $disk->get($path) ?? '');

        if (! Storage::disk(EmailAttachment::DISK)->exists($destination)) {
            return null;
        }

        return [
            'path' => $destination,
            'filename' => basename($path) !== '' && basename($path) !== '.'
                ? basename($path)
                : "inline.{$extension}",
            'content_id' => $this->makeContentId(),
        ];
    }

    private function authorizedStoragePath(User $user, string $sourcePath): ?string
    {
        $path = $this->normalizeStoragePath($sourcePath);

        if ($path === null) {
            return null;
        }

        $teamId = $user->current_team_id;

        if (blank($teamId)) {
            return null;
        }

        $directory = EmailAttachment::composeImagesDirectory((string) $teamId).'/';

        if (! str_starts_with($path, $directory)) {
            return null;
        }

        return $path;
    }

    private function normalizeStoragePath(string $sourcePath): ?string
    {
        $path = str_replace('\\', '/', $sourcePath);
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        return implode('/', $segments);
    }

    /**
     * @return array{path: string, filename: string, content_id: string}|null
     */
    private function copyDataUri(string $dataUri): ?array
    {
        if (! preg_match('#^data:(image/[^;]+);base64,(.+)$#i', $dataUri, $matches)) {
            return null;
        }

        $binary = base64_decode($matches[2], true);

        if ($binary === false) {
            return null;
        }

        $mimeType = mb_strtolower($matches[1]);
        $extension = $this->extensionFromMimeType($mimeType);
        $destination = 'email-attachments/'.Str::ulid().'.'.$extension;

        Storage::disk(EmailAttachment::DISK)->put($destination, $binary);

        return [
            'path' => $destination,
            'filename' => "inline.{$extension}",
            'content_id' => $this->makeContentId(),
        ];
    }

    private function makeContentId(): string
    {
        return Str::ulid().'@relaticle';
    }

    private function extensionFromMimeType(string $mimeType): string
    {
        $extensions = MimeTypes::getDefault()->getExtensions($mimeType);

        return $extensions[0] ?? 'bin';
    }
}
