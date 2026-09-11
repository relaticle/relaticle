<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

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
                $embedded = $this->copyToEmailAttachmentDisk($sourcePath);

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

        $path = parse_url($src, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (str_starts_with($path, '/storage/')) {
            return ltrim(mb_substr($path, mb_strlen('/storage/')), '/');
        }

        return ltrim($path, '/');
    }

    /**
     * @return array{path: string, filename: string, content_id: string}|null
     */
    private function copyToEmailAttachmentDisk(string $sourcePath): ?array
    {
        if (str_starts_with(mb_strtolower($sourcePath), 'data:image/')) {
            return $this->copyDataUri($sourcePath);
        }

        foreach ($this->candidateDisks() as $diskName) {
            $disk = Storage::disk($diskName);

            foreach ($this->candidateStoragePaths($sourcePath) as $path) {
                if (! $disk->exists($path)) {
                    continue;
                }

                $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';
                $extension = $this->extensionFromMimeType($mimeType);
                $destination = 'email-attachments/'.Str::ulid().'.'.$extension;

                Storage::disk(EmailAttachment::DISK)->put($destination, $disk->get($path) ?? '');

                if (! Storage::disk(EmailAttachment::DISK)->exists($destination)) {
                    continue;
                }

                return [
                    'path' => $destination,
                    'filename' => basename($path) !== '' && basename($path) !== '.'
                        ? basename($path)
                        : "inline.{$extension}",
                    'content_id' => $this->makeContentId(),
                ];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidateStoragePaths(string $sourcePath): array
    {
        $paths = [$sourcePath];

        if (! str_contains($sourcePath, '/')) {
            $paths[] = 'email-attachments/'.$sourcePath;
        }

        return array_values(array_unique($paths));
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

    /**
     * @return list<string>
     */
    private function candidateDisks(): array
    {
        $defaultDisk = (string) config('filament.default_filesystem_disk', 'local');

        return array_values(array_unique([
            EmailAttachment::DISK,
            'public',
            $defaultDisk,
            $defaultDisk === 'local' ? 'public' : 'local',
        ]));
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
