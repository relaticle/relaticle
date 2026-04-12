<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final readonly class EmailTemplateRenderService
{
    /**
     * Render template subject and body, substituting {variable} placeholders.
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
     * @return array<string, string>
     */
    private function buildVariables(?Model $record): array
    {
        if (! $record instanceof Model) {
            return [];
        }

        return match (true) {
            $record instanceof People => [
                '{name}' => $record->name,
                '{first_name}' => explode(' ', trim($record->name))[0],
                '{company}' => $record->company !== null ? $record->company->name : '',
            ],
            $record instanceof Company => [
                '{name}' => $record->name,
                '{first_name}' => $record->name,
                '{company}' => $record->name,
            ],
            $record instanceof Opportunity => [
                '{name}' => $record->name,
                '{first_name}' => explode(' ', trim($record->name))[0],
                '{company}' => $record->company !== null ? $record->company->name : '',
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function substitute(string $content, array $variables): string
    {
        if ($variables === []) {
            return $content;
        }

        return str_replace(array_keys($variables), array_values($variables), $content);
    }
}
