<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Actions\ResolveForwardingSenderAction;
use Relaticle\EmailIntegration\Actions\StoreInboundEmailAction;
use Relaticle\EmailIntegration\Models\TeamForwardingAddress;
use Relaticle\EmailIntegration\Services\PostmarkInboundAuthenticationValidator;
use Relaticle\EmailIntegration\Services\PostmarkInboundParser;
use Throwable;

final class ProcessInboundEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $payloadPath) {}

    public function handle(
        PostmarkInboundParser $parser,
        PostmarkInboundAuthenticationValidator $authenticator,
        ResolveForwardingSenderAction $resolveSender,
        StoreInboundEmailAction $store,
    ): void {
        $payload = $this->payload();

        if ($payload === null) {
            return;
        }

        $recipientAddresses = $parser->recipientAddresses($payload);

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

        $data = $parser->parse($payload);
        $fromAddress = collect($data->participants)->firstWhere('role', 'from')['email_address'] ?? null;

        if (! is_string($fromAddress) || $fromAddress === '') {
            return;
        }

        if (! $authenticator->passes($payload, $fromAddress)) {
            return;
        }

        $sender = $resolveSender->execute($team, $fromAddress);

        if (! $sender instanceof User) {
            return;
        }

        $store->execute($sender, $team, $data);

        Storage::disk('local')->delete($this->payloadPath);
    }

    public function failed(?Throwable $exception): void
    {
        Storage::disk('local')->delete($this->payloadPath);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(): ?array
    {
        if (! Storage::disk('local')->exists($this->payloadPath)) {
            return null;
        }

        $contents = Storage::disk('local')->get($this->payloadPath);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($contents, true);

        return is_array($payload) ? $payload : null;
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
