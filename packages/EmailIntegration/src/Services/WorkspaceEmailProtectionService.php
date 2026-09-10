<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

final readonly class WorkspaceEmailProtectionService
{
    /**
     * Corporate domains inferred from workspace members and connected mailboxes.
     * Consumer domains (gmail.com, etc.) are excluded.
     *
     * @return array<int, lowercase-string>
     */
    public function workspaceDomains(Team $team): array
    {
        $domains = collect();

        foreach ($team->allUsers() as $user) {
            $domain = $this->domainFromEmail((string) $user->email);

            if ($domain !== null && ! $this->isPublicEmailDomain($domain, $team)) {
                $domains->push($domain);
            }
        }

        ConnectedAccount::query()
            ->where('team_id', $team->getKey())
            ->pluck('email_address')
            ->each(function (mixed $emailAddress) use ($domains, $team): void {
                $domain = $this->domainFromEmail((string) $emailAddress);

                if ($domain !== null && ! $this->isPublicEmailDomain($domain, $team)) {
                    $domains->push($domain);
                }
            });

        return $domains
            ->map(fn (string $domain): string => strtolower($domain))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array{key: string, address: string, protection: string, source: string, is_system: bool}>
     */
    public function systemProtectionRows(Team $team): array
    {
        $rows = [[
            'key' => 'system-members',
            'address' => __('filament/pages/email-privacy-settings.privacy_protections.table.members_row'),
            'protection' => __('filament/pages/email-privacy-settings.privacy_protections.table.protected'),
            'source' => __('filament/pages/email-privacy-settings.privacy_protections.table.system_default'),
            'is_system' => true,
        ]];

        foreach ($this->workspaceDomains($team) as $domain) {
            $rows[] = [
                'key' => 'system-domain-'.$domain,
                'address' => $domain,
                'protection' => __('filament/pages/email-privacy-settings.privacy_protections.table.protected'),
                'source' => __('filament/pages/email-privacy-settings.privacy_protections.table.system_default'),
                'is_system' => true,
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ProtectedRecipient>  $customEntries
     * @return list<array{key: string, address: string, protection: string, source: string, is_system: bool, entry_id?: string}>
     */
    public function protectionTableRows(Team $team, Collection $customEntries): array
    {
        $customRows = $customEntries
            ->map(fn (ProtectedRecipient $entry): array => [
                'key' => 'custom-'.$entry->getKey(),
                'address' => $entry->value,
                'protection' => __('filament/pages/email-privacy-settings.privacy_protections.table.protected'),
                'source' => $entry->creator->name,
                'is_system' => false,
                'entry_id' => $entry->getKey(),
            ])
            ->all();

        return [...$this->systemProtectionRows($team), ...$customRows];
    }

    /**
     * Domains that are always protected for a workspace: inferred corporate domains
     * plus any custom domains an admin added manually.
     *
     * @return array<int, lowercase-string>
     */
    public function protectedDomainsForTeam(string $teamId): array
    {
        $team = Team::query()->find($teamId);

        if ($team === null) {
            return [];
        }

        $manualDomains = ProtectedRecipient::query()
            ->where('team_id', $teamId)
            ->where('type', 'domain')
            ->pluck('value')
            ->map(fn (mixed $value): string => strtolower((string) $value))
            ->all();

        return array_values(array_unique([
            ...$this->workspaceDomains($team),
            ...$manualDomains,
        ]));
    }

    public function isPublicEmailDomain(string $domain, Team $team): bool
    {
        $normalized = strtolower($domain);

        if ($this->configuredPublicDomains()->contains($normalized)) {
            return true;
        }

        return PublicEmailDomain::query()
            ->where('team_id', $team->getKey())
            ->whereRaw('lower(domain) = ?', [$normalized])
            ->exists();
    }

    private function domainFromEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        if (! str_contains($email, '@')) {
            return null;
        }

        $domain = Str::afterLast($email, '@');

        return $domain !== '' ? $domain : null;
    }

    /**
     * @return Collection<int, lowercase-string>
     */
    private function configuredPublicDomains(): Collection
    {
        return collect((array) config('email-integration.public_domains', []))
            ->map(fn (mixed $domain): string => strtolower((string) $domain));
    }
}
