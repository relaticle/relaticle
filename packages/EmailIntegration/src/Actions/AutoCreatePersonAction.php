<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\CreationSource;
use App\Models\People;
use App\Models\Workspace;
use App\Support\Database\AdvisoryLock;
use App\Support\EmailAddress;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\EmailIntegration\Support\EmailAddressHeaderParser;
use Relaticle\EmailIntegration\Support\PersonEmailMatcher;

final readonly class AutoCreatePersonAction
{
    public function __construct(
        private AdvisoryLock $advisoryLock,
        private PersonEmailMatcher $personEmailMatcher,
        private EmailAddressHeaderParser $headerParser,
    ) {}

    /**
     * Find-or-create a Person for the given email address.
     *
     * Identity is the email address, never the display name: two distinct people
     * who happen to share a name must stay distinct, and the same address reuses
     * the existing record rather than spawning a duplicate. The lock serialises
     * concurrent queue workers racing the same address so the check-then-create
     * stays atomic. New records use CreationSource::SYSTEM so they are
     * distinguishable from manually created ones.
     */
    public function execute(
        string $name,
        string $emailAddress,
        string $teamId,
        Workspace $team,
        ?string $companyId = null,
    ): People {
        $canonical = EmailAddress::canonicalize($emailAddress);
        $displayName = $this->headerParser->humanName($name, $canonical) ?? $canonical;
        $emailField = $this->personEmailMatcher->emailField($teamId);

        return $this->advisoryLock->transactional("auto-create-person:{$teamId}:{$canonical}", function () use ($displayName, $canonical, $teamId, $team, $companyId, $emailField): People {
            $existing = $this->personEmailMatcher->firstMatching($canonical, $teamId);

            if ($existing instanceof People) {
                return $existing;
            }

            $person = People::query()->create([
                'name' => $displayName,
                'workspace_id' => $teamId,
                'company_id' => $companyId,
                'creation_source' => CreationSource::SYSTEM,
            ]);

            if ($emailField instanceof BaseCustomField) {
                $person->saveCustomFieldValue($emailField, [$canonical], $team);
            }

            return $person;
        });
    }
}
