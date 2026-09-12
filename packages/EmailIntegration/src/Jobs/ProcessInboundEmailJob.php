<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Relaticle\EmailIntegration\Actions\ResolveForwardingSenderAction;
use Relaticle\EmailIntegration\Actions\StoreInboundEmailAction;
use Relaticle\EmailIntegration\Models\TeamForwardingAddress;
use Relaticle\EmailIntegration\Services\PostmarkInboundParser;

final class ProcessInboundEmailJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    public function handle(
        PostmarkInboundParser $parser,
        ResolveForwardingSenderAction $resolveSender,
        StoreInboundEmailAction $store,
    ): void {
        /** @var list<string> $recipientAddresses */
        $recipientAddresses = array_values($parser->recipientAddresses($this->payload));

        if ($recipientAddresses === []) {
            return;
        }

        $forwardingAddress = $this->resolveForwardingAddress($recipientAddresses);

        if (! $forwardingAddress instanceof TeamForwardingAddress) {
            return;
        }

        $team = Team::query()->find($forwardingAddress->team_id);

        if (! $team instanceof Team) {
            return;
        }

        $data = $parser->parse($this->payload);
        $fromAddress = collect($data->participants)->firstWhere('role', 'from')['email_address'] ?? null;

        if (! is_string($fromAddress) || $fromAddress === '') {
            return;
        }

        $sender = $resolveSender->execute($team, $fromAddress);

        if (! $sender instanceof User) {
            return;
        }

        $store->execute($sender, $team, $data);
    }

    /**
     * @param  list<string>  $recipientAddresses
     */
    private function resolveForwardingAddress(array $recipientAddresses): ?TeamForwardingAddress
    {
        $domain = strtolower((string) config('email-integration.inbound.domain'));

        foreach ($recipientAddresses as $address) {
            $localPart = $this->localPart($address, $domain);

            if ($localPart === null) {
                continue;
            }

            $match = TeamForwardingAddress::query()
                ->where('local_part', $localPart)
                ->first();

            if ($match instanceof TeamForwardingAddress) {
                return $match;
            }
        }

        return null;
    }

    private function localPart(string $address, string $domain): ?string
    {
        $normalized = strtolower(trim($address));
        $suffix = '@'.$domain;

        if (! str_ends_with($normalized, $suffix)) {
            return null;
        }

        $localPart = substr($normalized, 0, -strlen($suffix));

        return $localPart !== '' ? $localPart : null;
    }
}
