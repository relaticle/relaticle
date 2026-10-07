<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\EmailIntegration\Actions\PrepareAgentEmail;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

trait DescribesEmailProposals
{
    private const int MAX_EMAILS_PER_CALL = 5;

    public function schema(JsonSchema $schema): array
    {
        $properties = parent::schema($schema);

        $properties['records']->description(
            'The emails to propose. Pass ONE item for a single email, or up to '.self::MAX_EMAILS_PER_CALL
            .' items to propose them all in ONE card (never loop one call per email).',
        );

        return $properties;
    }

    public function handle(Request $request): string
    {
        $records = $request['records'] ?? null;

        if (is_array($records) && count($records) > self::MAX_EMAILS_PER_CALL) {
            return (string) json_encode([
                'error' => 'At most '.self::MAX_EMAILS_PER_CALL.' emails per proposal. Nothing was proposed.',
            ], JSON_UNESCAPED_SLASHES);
        }

        return parent::handle($request);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function emailData(array $record): array
    {
        $data = array_filter(
            Arr::only($record, ['connected_account_id', 'to', 'cc', 'bcc', 'subject', 'body', 'in_reply_to_email_id']),
            static fn (mixed $value): bool => $value !== null,
        );

        $data['include_signature'] = filter_var($record['include_signature'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $rules
     * @param  Closure(): mixed  $prepare
     */
    protected function emailError(array $data, array $rules, Closure $prepare): ?string
    {
        $validator = Validator::make($data, $rules, [
            'to.max' => PrepareAgentEmail::RECIPIENT_LIMIT_MESSAGE,
            'cc.max' => PrepareAgentEmail::RECIPIENT_LIMIT_MESSAGE,
            'bcc.max' => PrepareAgentEmail::RECIPIENT_LIMIT_MESSAGE,
        ]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        try {
            $prepare();
        } catch (ValidationException $exception) {
            return Arr::first(Arr::flatten($exception->errors()));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{label: string, value: string}>
     */
    protected function emailRows(array $record): array
    {
        $data = $this->emailData($record);

        $rows = [
            ['label' => 'Subject', 'value' => (string) ($data['subject'] ?? '')],
            ['label' => 'From', 'value' => $this->mailboxAddress((string) ($data['connected_account_id'] ?? ''))],
        ];

        foreach (['to' => 'To', 'cc' => 'CC', 'bcc' => 'BCC'] as $key => $label) {
            $addresses = array_values(array_filter((array) ($data[$key] ?? []), is_string(...)));

            if ($addresses !== []) {
                $rows[] = ['label' => $label, 'value' => implode(', ', $addresses)];
            }
        }

        $body = (string) ($data['body'] ?? '');

        if (trim($body) !== '') {
            $rows[] = ['label' => 'Message', 'value' => $body];
        }

        $rows[] = ['label' => 'Signature', 'value' => $data['include_signature'] === true ? 'Default' : 'None'];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function emailSummary(string $action, array $record): string
    {
        $data = $this->emailData($record);
        $recipients = array_values(array_filter((array) ($data['to'] ?? []), is_string(...)));
        $subject = (string) ($data['subject'] ?? '');

        return $recipients === []
            ? "{$action}: {$subject}"
            : "{$action} to {$recipients[0]}: {$subject}";
    }

    private function mailboxAddress(string $accountId): string
    {
        /** @var User $user */
        $user = auth()->user();

        return (string) ConnectedAccount::query()
            ->ownedBy($user, $user->currentWorkspace)
            ->whereKey($accountId)
            ->value('email_address');
    }
}
