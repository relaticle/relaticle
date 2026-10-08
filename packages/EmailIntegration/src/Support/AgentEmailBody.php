<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Dom\HTMLDocument;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

final readonly class AgentEmailBody
{
    public function __construct(private EmailTemplateRenderService $renderer) {}

    public function forDraft(string $markdown, ConnectedAccount $account, bool $includeSignature): string
    {
        return $this->renderer->applySignatureBlock(
            $this->html($markdown),
            $this->signatureForDraft($markdown, $account, $includeSignature),
        );
    }

    public function forSending(string $markdown, ConnectedAccount $account, bool $includeSignature): string
    {
        $signature = $this->signatureForSending($account, $includeSignature);

        if (! $signature instanceof EmailSignature) {
            return $this->html($markdown);
        }

        return $this->html($markdown).$this->renderer->renderForSending(
            $this->renderer->applySignatureBlock('', $signature),
        );
    }

    public function signatureForDraft(string $markdown, ConnectedAccount $account, bool $includeSignature): ?EmailSignature
    {
        return $this->signatureForSending($account, $includeSignature && trim($markdown) !== '');
    }

    public function signatureForSending(ConnectedAccount $account, bool $includeSignature): ?EmailSignature
    {
        return $includeSignature
            ? EmailSignature::query()->defaultFor((string) $account->getKey())->first()
            : null;
    }

    private function html(string $markdown): string
    {
        $html = trim(Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'max_delimiters_per_line' => 200,
            'renderer' => ['soft_break' => "<br />\n"],
        ]));

        return str_contains($html, '<img') ? $this->withoutImages($html) : $html;
    }

    private function withoutImages(string $html): string
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        foreach ($document->querySelectorAll('img') as $image) {
            $image->replaceWith($document->createTextNode($image->getAttribute('alt') ?? ''));
        }

        return $document->body->innerHTML;
    }
}
