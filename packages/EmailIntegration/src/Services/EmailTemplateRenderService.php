<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
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
            'subject' => $this->substitute($template->subject ?? '', $variables),
            'body_html' => $this->substitute($template->body_html ?? '', $variables),
        ];
    }

    /**
     * Substitute merge-tag placeholders in a content string.
     */
    public function renderContent(string $content, ?Model $record = null): string
    {
        return $this->substitute($content, $this->buildVariables($record));
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
     * @param  array<string, string>  $variables
     */
    private function substitute(string $content, array $variables): string
    {
        if ($variables === []) {
            return $content;
        }

        $content = preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn (array $matches): string => $variables[$matches[1]] ?? $matches[0],
            $content
        ) ?? $content;

        $legacyPairs = [];
        foreach ($variables as $key => $value) {
            $legacyPairs['{'.$key.'}'] = $value;
        }

        return str_replace(array_keys($legacyPairs), array_values($legacyPairs), $content);
    }
}
