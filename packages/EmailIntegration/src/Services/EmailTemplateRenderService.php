<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final readonly class EmailTemplateRenderService
{
    /**
     * Available merge tags with human-readable labels for Filament's RichEditor.
     *
     * @var array<string, string>
     */
    public const array MERGE_TAGS = [
        'name' => 'Full name',
        'first_name' => 'First name',
        'company' => 'Company',
        'today' => "Today's date",
    ];

    /**
     * Render template subject and body, substituting merge-tag placeholders.
     *
     * @return array{subject: string, body_html: string}
     */
    public function render(EmailTemplate $template, ?Model $record = null): array
    {
        $variables = $this->buildVariables($record);

        return [
            // Subject is plain text; body is HTML, so merge values must be HTML-escaped
            // there to stop attacker-influenced CRM field values (e.g. a contact name)
            // injecting markup into the rendered/sent email.
            'subject' => $this->substitute($template->subject ?? '', $variables),
            'body_html' => $this->substitute($template->body_html ?? '', $variables, escapeHtml: true),
        ];
    }

    /**
     * Render a template and keep the signature attached below its body as a
     * dedicated signature block, so applying a template never discards it.
     *
     * @return array{subject: string, body_html: string}
     */
    public function renderWithSignature(EmailTemplate $template, ?Model $record = null, ?EmailSignature $signature = null): array
    {
        $rendered = $this->render($template, $record);
        $rendered['body_html'] = $this->applySignatureBlock($rendered['body_html'], $signature);

        return $rendered;
    }

    /**
     * Replace the body's signature block: strip any existing signature block,
     * then append a fresh one for the given signature (or none when null).
     *
     * Used by every compose mutation (default load, account change, template
     * select, signature dropdown) so the signature is added and removed in one
     * deterministic place.
     */
    public function applySignatureBlock(string $bodyHtml, ?EmailSignature $signature): string
    {
        $body = rtrim($this->stripSignatureBlock($bodyHtml));

        if (! $signature instanceof EmailSignature) {
            return $body;
        }

        return $body.$this->signatureBlockHtml($signature);
    }

    /**
     * Remove any signature block node(s) from a body, leaving the rest intact.
     */
    public function stripSignatureBlock(string $bodyHtml): string
    {
        $pattern = '#<div\b[^>]*\bdata-id="'.preg_quote(SignatureBlock::ID, '#').'"[^>]*>\s*</div>#i';

        return preg_replace($pattern, '', $bodyHtml) ?? $bodyHtml;
    }

    /**
     * Render the editor body to final email HTML, expanding the signature block
     * into the signature's content and substituting any leftover merge tags.
     */
    public function renderForSending(string $bodyHtml, ?Model $record = null): string
    {
        $html = RichContentRenderer::make($bodyHtml)
            ->customBlocks([SignatureBlock::class])
            ->toHtml();

        return $this->renderContent($html, $record);
    }

    /**
     * Build the HTML for a signature block node the RichEditor can parse.
     */
    private function signatureBlockHtml(EmailSignature $signature): string
    {
        $config = ['signature_id' => $signature->getKey()];

        $configAttr = htmlspecialchars(
            (string) json_encode($config),
            ENT_QUOTES,
            'UTF-8',
        );

        $previewAttr = base64_encode((string) SignatureBlock::toPreviewHtml($config));
        $labelAttr = htmlspecialchars(SignatureBlock::getPreviewLabel($config), ENT_QUOTES, 'UTF-8');

        return '<div data-type="customBlock"'
            .' data-id="'.SignatureBlock::ID.'"'
            .' data-config="'.$configAttr.'"'
            .' data-label="'.$labelAttr.'"'
            .' data-preview="'.$previewAttr.'"></div>';
    }

    /**
     * Substitute merge-tag placeholders in a content string.
     */
    public function renderContent(string $content, ?Model $record = null): string
    {
        // Used on already-rendered email HTML (renderForSending), so escape values.
        return $this->substitute($content, $this->buildVariables($record), escapeHtml: true);
    }

    /**
     * @return array<string, string>
     */
    private function buildVariables(?Model $record): array
    {
        $baseVariables = [
            'today' => Date::now()->toFormattedDateString(),
        ];

        if (! $record instanceof Model) {
            return $baseVariables;
        }

        $recordVariables = match (true) {
            $record instanceof People => [
                'name' => (string) $record->name,
                'first_name' => explode(' ', trim((string) $record->name))[0],
                'company' => $record->company !== null ? (string) $record->company->name : '',
            ],
            $record instanceof Company => [
                'name' => (string) $record->name,
                'first_name' => (string) $record->name,
                'company' => (string) $record->name,
            ],
            $record instanceof Opportunity => [
                'name' => (string) $record->name,
                'first_name' => explode(' ', trim((string) $record->name))[0],
                'company' => $record->company !== null ? (string) $record->company->name : '',
            ],
            default => [],
        };

        return [...$baseVariables, ...$recordVariables];
    }

    /**
     * Replace both legacy `{name}` and Filament v5 `{{ name }}` merge tags.
     *
     * When $escapeHtml is true the substituted values are HTML-escaped — required
     * whenever the result is HTML (body), so a merge value can never inject markup.
     * Substitution happens in a single pass (callback for `{{ }}`, then strtr for the
     * legacy `{ }` form) so an injected value is never re-scanned for further tags.
     *
     * @param  array<string, string>  $variables
     */
    private function substitute(string $content, array $variables, bool $escapeHtml = false): string
    {
        if ($variables === []) {
            return $content;
        }

        $resolve = fn (string $value): string => $escapeHtml
            ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            : $value;

        $content = preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn (array $matches): string => isset($variables[$matches[1]])
                ? $resolve($variables[$matches[1]])
                : $matches[0],
            $content
        ) ?? $content;

        $legacyPairs = [];
        foreach ($variables as $key => $value) {
            $legacyPairs['{'.$key.'}'] = $resolve($value);
        }

        // strtr replaces the longest keys first and never re-scans replacements,
        // so a value containing another `{tag}` is not recursively substituted.
        return strtr($content, $legacyPairs);
    }
}
