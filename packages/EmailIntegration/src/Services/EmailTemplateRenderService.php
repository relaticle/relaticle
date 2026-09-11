<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Models\EmailAttachment;
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
        'company' => 'Company',
        'phone_number' => 'Phone number',
        'job_title' => 'Job title',
        'linkedin' => 'LinkedIn',
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
        $variables = $this->buildVariables($record);

        $html = RichContentRenderer::make($bodyHtml)
            ->mergeTags($variables)
            ->customBlocks([SignatureBlock::class])
            ->fileAttachmentsDisk(EmailAttachment::DISK)
            ->fileAttachmentsVisibility('private')
            ->toHtml();

        return $this->renderContent($this->unwrapMergeTagSpans($html), $record);
    }

    /**
     * RichEditor merge tags render as {@code span} nodes; once resolved, unwrap them
     * so outbound HTML and the email preview sanitizer do not leave empty spans behind.
     */
    private function unwrapMergeTagSpans(string $html): string
    {
        $pattern = '#<span\b[^>]*\bdata-type="mergeTag"[^>]*>(.*?)</span>#is';

        return preg_replace($pattern, '$1', $html) ?? $html;
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
     * Substitute merge-tag placeholders in a plain-text string.
     */
    public function renderPlainText(string $content, ?Model $record = null): string
    {
        return $this->substitute($content, $this->buildVariables($record));
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
                'company' => $record->company !== null ? (string) $record->company->name : '',
                ...$this->peopleCustomFieldValues($record),
            ],
            $record instanceof Company => [
                'name' => (string) $record->name,
                'company' => (string) $record->name,
                ...$this->emptyPeopleCustomFieldValues(),
            ],
            $record instanceof Opportunity => [
                'name' => (string) $record->name,
                'company' => $record->company !== null ? (string) $record->company->name : '',
                ...$this->emptyPeopleCustomFieldValues(),
            ],
            default => [],
        };

        return [...$baseVariables, ...$recordVariables];
    }

    /**
     * @return array<string, string>
     */
    private function peopleCustomFieldValues(People $person): array
    {
        $codes = [
            'phone_number' => PeopleField::PHONE_NUMBER->value,
            'job_title' => PeopleField::JOB_TITLE->value,
            'linkedin' => PeopleField::LINKEDIN->value,
        ];

        $fields = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $person->team_id)
            ->where('entity_type', 'people')
            ->whereIn('code', array_values($codes))
            ->get()
            ->keyBy('code');

        $person->loadMissing(['customFieldValues.customField']);

        $values = [];

        foreach ($codes as $key => $code) {
            $field = $fields->get($code);

            if (! $field instanceof CustomField) {
                $values[$key] = '';

                continue;
            }

            $value = $person->getCustomFieldValue($field);

            $values[$key] = $this->customFieldMergeValue($value);
        }

        return $values;
    }

    private function customFieldMergeValue(mixed $value): string
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (in_array($value, [null, '', []], true)) {
            return '';
        }

        if (is_array($value)) {
            $strings = array_values(array_filter(
                array_map(static fn (mixed $item): string => (string) $item, $value),
                static fn (string $item): bool => $item !== '',
            ));

            return implode(', ', $strings);
        }

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    private function emptyPeopleCustomFieldValues(): array
    {
        return [
            'phone_number' => '',
            'job_title' => '',
            'linkedin' => '',
        ];
    }

    /**
     * Replace both legacy `{name}` and Filament v5 `{{ name }}` merge tags.
     *
     * When $escapeHtml is true the substituted values are HTML-escaped, which is required
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
            '/\{\{\s*([^}]+?)\s*\}\}/',
            function (array $matches) use ($variables, $resolve): string {
                $tagKey = $this->resolveMergeTagKey($matches[1]);

                if ($tagKey === null || ! array_key_exists($tagKey, $variables)) {
                    return $matches[0];
                }

                return $resolve($variables[$tagKey]);
            },
            $content
        ) ?? $content;

        $legacyPairs = [];
        foreach ($variables as $key => $value) {
            $legacyPairs['{'.$key.'}'] = $resolve($value);

            $label = self::MERGE_TAGS[$key] ?? null;

            if ($label !== null) {
                $legacyPairs['{'.$label.'}'] = $resolve($value);
            }
        }

        // strtr replaces the longest keys first and never re-scans replacements,
        // so a value containing another `{tag}` is not recursively substituted.
        return strtr($content, $legacyPairs);
    }

    private function resolveMergeTagKey(string $raw): ?string
    {
        $normalized = strtolower(trim($raw));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return $this->mergeTagKeyAliases()[$normalized] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function mergeTagKeyAliases(): array
    {
        static $aliases = null;

        if (is_array($aliases)) {
            return $aliases;
        }

        $aliases = [
            'name' => 'name',
            'full name' => 'name',
            'full_name' => 'name',
            'company' => 'company',
            'today' => 'today',
            "today's date" => 'today',
            'todays date' => 'today',
        ];

        foreach (self::MERGE_TAGS as $key => $label) {
            $aliases[strtolower($label)] = $key;
            $aliases[str_replace(' ', '_', strtolower($label))] = $key;
        }

        return $aliases;
    }
}
