<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Enums\CustomFieldType;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class EmailVariableCatalogue
{
    /** @var array<string, string> */
    public const array LEGACY_TAGS = [
        'name' => 'Full name',
        'company' => 'Company',
        'phone_number' => 'Phone number',
        'job_title' => 'Job title',
        'linkedin' => 'LinkedIn',
        'today' => "Today's date",
    ];

    /** @return array<string, string> */
    public function variables(User $viewer): array
    {
        $labels = [
            'person.name' => __('filament/emails/composer.variables.person_name'),
            'person.first_name' => __('filament/emails/composer.variables.first_name'),
            'company.name' => __('filament/emails/composer.variables.company_name'),
            'opportunity.name' => __('filament/emails/composer.variables.opportunity_name'),
            'sender.name' => __('filament/emails/composer.variables.sender_name'),
            'today' => __('filament/emails/composer.variables.today'),
        ];

        foreach ($this->fields((string) $viewer->current_workspace_id) as $field) {
            $labels[$this->prefix($field).'.'.$field->code] = $field->name;
        }

        return $labels;
    }

    /** @return array<string, string> */
    public function values(?Model $record, ?User $sender = null): array
    {
        $values = ['today' => now()->toFormattedDateString()];
        $workspaceId = $sender instanceof User ? $sender->current_workspace_id : $record?->getAttribute('workspace_id');
        if ($workspaceId === null) {
            return $values;
        }

        $person = $record instanceof People ? $record : ($record instanceof Opportunity ? $record->contact : null);
        $company = $record instanceof Company ? $record : ($record instanceof People || $record instanceof Opportunity ? $record->company : null);
        $opportunity = $record instanceof Opportunity ? $record : null;
        $values += [
            'person.name' => (string) $person?->name,
            'person.first_name' => Str::before((string) $person?->name, ' '),
            'company.name' => (string) $company?->name,
            'opportunity.name' => (string) $opportunity?->name,
            'sender.name' => (string) $sender?->name,
        ];

        $records = ['people' => $person, 'company' => $company, 'opportunity' => $opportunity];
        foreach ($this->fields((string) $workspaceId) as $field) {
            $owner = $records[$field->entity_type];
            $values[$this->prefix($field).'.'.$field->code] = $owner === null ? '' : $this->fieldValue($owner, $field);
        }

        if ($record instanceof Model || $sender instanceof User) {
            $values += [
                'name' => (string) $record?->getAttribute('name'),
                'company' => (string) $company?->name,
                'phone_number' => $values['person.phone_number'] ?? '',
                'job_title' => $values['person.job_title'] ?? '',
                'linkedin' => $values['person.linkedin'] ?? '',
            ];
        }

        return $values;
    }

    /** @return Collection<int, CustomField> */
    private function fields(string $workspaceId): Collection
    {
        return CustomField::query()->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->whereIn('entity_type', ['people', 'company', 'opportunity'])
            ->active()
            ->whereNotIn('type', [CustomFieldType::RICH_EDITOR, CustomFieldType::FILE_UPLOAD, CustomFieldType::RECORD, CustomFieldType::CHECKBOX, CustomFieldType::TOGGLE, CustomFieldType::COLOR_PICKER])
            ->with('options')
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    private function prefix(CustomField $field): string
    {
        return $field->entity_type === 'people' ? 'person' : $field->entity_type;
    }

    private function fieldValue(People|Company|Opportunity $record, CustomField $field): string
    {
        $record->loadMissing('customFieldValues.customField');
        $value = $record->getCustomFieldValue($field);

        if ($value instanceof CarbonInterface) {
            return $field->type === CustomFieldType::DATE->value ? $value->toDateString() : $value->toIso8601String();
        }

        if ($value === null || $value === '') {
            return '';
        }

        $choices = in_array($field->type, [CustomFieldType::SELECT->value, CustomFieldType::RADIO->value, CustomFieldType::TOGGLE_BUTTONS->value, CustomFieldType::MULTI_SELECT->value, CustomFieldType::CHECKBOX_LIST->value], true);
        $items = $value instanceof Collection ? $value->all() : (is_array($value) ? $value : [$value]);

        return implode(', ', array_map(
            fn (mixed $item): string => $choices ? (string) ($field->options->where('id', $item)->value('name') ?? '') : (string) $item,
            $items,
        ));
    }

    public function key(string $raw): string
    {
        $key = Str::squish(Str::lower($raw));
        $aliases = ['full_name' => 'name', 'todays date' => 'today'];
        foreach (self::LEGACY_TAGS as $code => $label) {
            $aliases[strtolower($label)] = $code;
            $aliases[str_replace(' ', '_', strtolower($label))] = $code;
        }

        return $aliases[$key] ?? $key;
    }

    public function assertResolved(string $subject, string $bodyHtml): void
    {
        preg_match_all('/\{\{\s*([^}]+?)\s*\}\}/', $subject.' '.$bodyHtml, $matches);
        if ($matches[1] === []) {
            return;
        }

        throw ValidationException::withMessages([
            'bodyHtml' => __('filament/emails/composer.variables.unresolved', ['tags' => implode(', ', array_unique($matches[1]))]),
        ]);
    }

    /**
     * @param  list<array{email: string, record: Model|null}>  $recipients
     * @return array{unknown: list<string>, empty: array<string, list<string>>}
     */
    public function check(string $subject, string $bodyHtml, array $recipients, User $sender): array
    {
        preg_match_all('/\{\{\s*([^}]+?)\s*\}\}/', $subject.' '.html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8'), $matches);
        $result = ['unknown' => [], 'empty' => []];
        foreach ($recipients as $recipient) {
            $values = $this->values($recipient['record'], $sender);
            foreach ($matches[1] as $token) {
                [$raw, $fallback] = array_pad(explode('|', $token, 2), 2, '');
                $key = $this->key($raw);
                if (! array_key_exists($key, $values)) {
                    $result['unknown'][] = $key;
                } elseif ($values[$key] === '' && trim($fallback) === '') {
                    $result['empty'][$key][] = $recipient['email'];
                }
            }
        }

        $result['unknown'] = array_values(array_unique($result['unknown']));
        $result['empty'] = array_map(fn (array $addresses): array => array_values(array_unique($addresses)), $result['empty']);

        return $result;
    }
}
