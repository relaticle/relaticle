<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class AllowedRecipientService
{
    public function __construct(
        private RecipientSuggestionService $recipientSuggestions,
    ) {}

    /**
     * @param  list<string>  $extraAddresses  Reply-thread participants the composer pre-filled.
     */
    public function isAllowed(User $user, string $email, array $extraAddresses = []): bool
    {
        $normalized = $this->normalize($email);

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->addressesFor($user, $extraAddresses), true);
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<string>  $extraAddresses
     * @return array<string, list<string>>
     */
    public function validationErrors(
        User $user,
        array $to,
        array $cc,
        array $bcc,
        array $extraAddresses = [],
    ): array {
        $message = __('filament/emails/composer.validation.recipient_not_allowed');
        $errors = [];

        foreach (['to' => $to, 'cc' => $cc, 'bcc' => $bcc] as $field => $addresses) {
            foreach ($addresses as $index => $address) {
                if (! $this->isAllowed($user, $address, $extraAddresses)) {
                    $errors["{$field}.{$index}"] = [$message];
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $extraAddresses
     * @return list<string>
     */
    public function addressesFor(User $user, array $extraAddresses = []): array
    {
        $addresses = [
            ...$this->recipientSuggestions->addressesFor($user),
            ...$this->crmPrimaryEmailsForTeam((string) $user->current_team_id),
            ...array_map($this->normalize(...), $extraAddresses),
        ];

        return array_values(array_unique(array_filter($addresses)));
    }

    /**
     * @return list<string>
     */
    private function crmPrimaryEmailsForTeam(string $teamId): array
    {
        $emailField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', 'people')
            ->where('code', PeopleField::EMAILS->value)
            ->first();

        if (! $emailField instanceof CustomField) {
            return [];
        }

        $addresses = [];

        foreach (CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', 'people')
            ->where('custom_field_id', $emailField->getKey())
            ->get(['json_value']) as $value) {
            $email = $this->primaryEmailFromValue($value->json_value);

            if ($email !== null) {
                $addresses[] = $this->normalize($email);
            }
        }

        return $addresses;
    }

    private function primaryEmailFromValue(mixed $value): ?string
    {
        $emails = $value instanceof Collection
            ? $value
            : collect(is_array($value) ? $value : []);

        $email = $emails
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->first();

        return is_string($email) ? $email : null;
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
