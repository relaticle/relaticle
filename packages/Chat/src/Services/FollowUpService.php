<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

final readonly class FollowUpService
{
    private const int MAX_CHIPS = 3;

    /**
     * @param  array<int, array{name: string, result?: mixed}>  $toolCalls
     * @return array<int, array{label: string, prompt: string}>
     */
    public function suggest(array $toolCalls): array
    {
        if ($toolCalls === []) {
            return [];
        }

        foreach ($toolCalls as $call) {
            $normalized = $this->normalizeToolName($call['name']);
            if (str_starts_with($normalized, 'create_')
                || str_starts_with($normalized, 'update_')
                || str_starts_with($normalized, 'delete_')
            ) {
                return [];
            }
        }

        $last = $toolCalls[array_key_last($toolCalls)];
        $name = $this->normalizeToolName($last['name']);

        $chips = match ($name) {
            'list_companies' => $this->forCompanyList($last),
            'list_people' => $this->forPeopleList($last),
            'list_opportunities' => $this->forOpportunityList(),
            'list_tasks' => $this->forTaskList(),
            'list_notes' => [],
            'get_company' => $this->forCompanyShow($last),
            'get_person' => $this->forPersonShow(),
            'get_opportunity' => $this->forOpportunityShow(),
            'get_task' => $this->forTaskShow(),
            'get_crm_summary' => $this->forCrmSummary(),
            'search_crm' => [],
            default => [],
        };

        return array_slice($chips, 0, self::MAX_CHIPS);
    }

    /**
     * Convert PascalCase class basenames (e.g. "ListCompaniesTool") to canonical
     * snake_case keys (e.g. "list_companies"). Already snake_case names pass through.
     */
    private function normalizeToolName(string $name): string
    {
        if ($name === '') {
            return '';
        }

        $withoutSuffix = preg_replace('/Tool$/', '', $name) ?? $name;
        $snake = preg_replace('/(?<!^)([A-Z])/', '_$1', $withoutSuffix) ?? $withoutSuffix;
        $snake = strtolower($snake);

        return match ($snake) {
            'list_persons' => 'list_people',
            'list_peoples' => 'list_people',
            default => $snake,
        };
    }

    /**
     * @param  array{name: string, result?: mixed}  $call
     * @return array<int, array{label: string, prompt: string}>
     */
    private function forCompanyList(array $call): array
    {
        $chips = [];
        $first = $this->firstResultName($call);

        if ($first !== null) {
            $chips[] = [
                'label' => "Details for {$first}",
                'prompt' => "Show me details for {$first}",
            ];
        }

        $chips[] = [
            'label' => 'Filter by industry',
            'prompt' => 'Filter companies by industry',
        ];

        return $chips;
    }

    /**
     * @param  array{name: string, result?: mixed}  $call
     * @return array<int, array{label: string, prompt: string}>
     */
    private function forPeopleList(array $call): array
    {
        $chips = [];
        $first = $this->firstNestedCompanyName($call);

        if ($first !== null) {
            $chips[] = [
                'label' => "Contacts at {$first}",
                'prompt' => "Show me contacts at {$first}",
            ];
        }

        $chips[] = [
            'label' => 'Filter by role',
            'prompt' => 'Filter people by role',
        ];

        return $chips;
    }

    /**
     * @return array<int, array{label: string, prompt: string}>
     */
    private function forOpportunityList(): array
    {
        return [
            ['label' => 'Group by stage', 'prompt' => 'Group opportunities by stage'],
            ['label' => 'Show overdue deals', 'prompt' => 'Show overdue opportunities'],
        ];
    }

    /**
     * @return array<int, array{label: string, prompt: string}>
     */
    private function forTaskList(): array
    {
        return [
            ['label' => 'Filter by status', 'prompt' => 'Filter tasks by status'],
            ['label' => 'Show overdue', 'prompt' => 'Show overdue tasks'],
        ];
    }

    /**
     * @param  array{name: string, result?: mixed}  $call
     * @return array<int, array{label: string, prompt: string}>
     */
    private function forCompanyShow(array $call): array
    {
        $name = $this->resultName($call);
        $reference = $name ?? 'this company';

        return [
            ['label' => "Contacts at {$reference}", 'prompt' => "Show contacts at {$reference}"],
            ['label' => "Opportunities at {$reference}", 'prompt' => "Show opportunities at {$reference}"],
            ['label' => 'Recent notes', 'prompt' => "Show recent notes for {$reference}"],
        ];
    }

    /**
     * @return array<int, array{label: string, prompt: string}>
     */
    private function forPersonShow(): array
    {
        return [
            ['label' => 'Show their company', 'prompt' => 'Show this person\'s company'],
            ['label' => 'Show their opportunities', 'prompt' => 'Show this person\'s opportunities'],
            ['label' => 'Show their tasks', 'prompt' => 'Show tasks assigned to this person'],
        ];
    }

    /**
     * @return array<int, array{label: string, prompt: string}>
     */
    private function forOpportunityShow(): array
    {
        return [
            ['label' => 'Show contact', 'prompt' => 'Show the contact for this opportunity'],
            ['label' => 'Show company', 'prompt' => 'Show the company for this opportunity'],
            ['label' => 'Show notes', 'prompt' => 'Show notes for this opportunity'],
        ];
    }

    /**
     * @return array<int, array{label: string, prompt: string}>
     */
    private function forTaskShow(): array
    {
        return [
            ['label' => 'Mark complete', 'prompt' => 'Mark this task as complete'],
            ['label' => 'Show related', 'prompt' => 'Show related records for this task'],
        ];
    }

    /**
     * @return array<int, array{label: string, prompt: string}>
     */
    private function forCrmSummary(): array
    {
        return [
            ['label' => 'Pipeline by stage', 'prompt' => 'Show pipeline by stage'],
            ['label' => 'Overdue tasks', 'prompt' => 'Show overdue tasks'],
            ['label' => 'Recent activity', 'prompt' => 'Show recent activity'],
        ];
    }

    /**
     * Pull the first record's display name from a list-style tool result.
     *
     * @param  array{name: string, result?: mixed}  $call
     */
    private function firstResultName(array $call): ?string
    {
        $items = $this->extractItems($call['result'] ?? null);
        if ($items === []) {
            return null;
        }

        return $this->pickName($items[0]);
    }

    /**
     * Pull the first nested company name from a list-of-people result.
     *
     * @param  array{name: string, result?: mixed}  $call
     */
    private function firstNestedCompanyName(array $call): ?string
    {
        $items = $this->extractItems($call['result'] ?? null);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $company = $item['company'] ?? null;
            if (is_array($company)) {
                $name = $this->pickName($company);
                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Pull the display name from a single-record tool result.
     *
     * @param  array{name: string, result?: mixed}  $call
     */
    private function resultName(array $call): ?string
    {
        $result = $this->decodeIfJson($call['result'] ?? null);
        if (! is_array($result)) {
            return null;
        }

        // Single-record API resources commonly wrap under "data".
        $candidate = is_array($result['data'] ?? null) ? $result['data'] : $result;

        return $this->pickName($candidate);
    }

    /**
     * @return array<int, mixed>
     */
    private function extractItems(mixed $result): array
    {
        $decoded = $this->decodeIfJson($result);

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        if (! array_is_list($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function decodeIfJson(mixed $result): mixed
    {
        if (is_string($result)) {
            $decoded = json_decode($result, true);

            return is_array($decoded) ? $decoded : null;
        }

        return $result;
    }

    private function pickName(mixed $item): ?string
    {
        if (! is_array($item)) {
            return null;
        }

        if (is_string($item['name'] ?? null) && $item['name'] !== '') {
            return $item['name'];
        }

        if (is_string($item['title'] ?? null) && $item['title'] !== '') {
            return $item['title'];
        }

        return null;
    }
}
