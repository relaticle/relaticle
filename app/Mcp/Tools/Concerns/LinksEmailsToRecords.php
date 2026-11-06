<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Enums\EmailGrant;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\EmailLinkTargetsQuery;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;

trait LinksEmailsToRecords
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;

    public function shouldRegister(): bool
    {
        return $this->holdsAnyEmailGrant(EmailGrant::Draft);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'links' => $schema->array()
                ->items($schema->object([
                    'email_id' => $schema->string()->description('The email ID, as returned by the list emails tool.')->required(),
                    'record_type' => $schema->string()->enum(EmailLinkTargetsQuery::types())->description('The kind of record.')->required(),
                    'record_id' => $schema->string()->description('The record ID.')->required(),
                ]))
                ->description('Up to 20 email and record pairs. The whole conversation of each email moves together.')
                ->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->required(),
        ];
    }

    protected function idempotentHint(): bool
    {
        return true;
    }

    /** @param  Closure(User, Email, Company|People|Opportunity): void  $apply */
    protected function applyToEveryLink(Request $request, bool $linkedAfter, Closure $apply): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenLacks(EmailGrant::Draft) ?? $this->denyIfTokenCannot('update')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var array{links: list<array{email_id: string, record_type: string, record_id: string}>} $validated */
        $validated = $request->validate([
            'links' => ['required', 'array', 'min:1', 'max:20'],
            'links.*.email_id' => ['required', 'string', 'max:64'],
            'links.*.record_type' => ['required', 'string', Rule::in(EmailLinkTargetsQuery::types())],
            'links.*.record_id' => ['required', 'string', 'max:64'],
        ]);

        $pairs = [];

        foreach ($validated['links'] as $link) {
            $pair = $this->resolveLinkPair($user, $link);

            if ($pair instanceof Response) {
                return $pair;
            }

            $pairs[] = [...$pair, 'link' => $link];
        }

        $items = DB::transaction(function () use ($pairs, $apply, $user, $linkedAfter): array {
            $items = [];

            foreach ($pairs as ['email' => $email, 'record' => $record, 'link' => $link]) {
                $apply($user, $email, $record);
                $items[] = [...$link, 'linked' => $linkedAfter];
            }

            return $items;
        });

        return Response::structured(['items' => $items]);
    }

    /**
     * @param  array{email_id: string, record_type: string, record_id: string}  $link
     * @return array{email: Email, record: Company|People|Opportunity}|Response
     */
    private function resolveLinkPair(User $user, array $link): array|Response
    {
        $email = resolve(VisibleEmailsQuery::class)->find($user, $link['email_id']);

        if (! $email instanceof Email) {
            return Response::error("Email with ID [{$link['email_id']}] not found. Nothing was changed.");
        }

        $record = resolve(EmailLinkTargetsQuery::class)->find($user, $link['record_type'], $link['record_id']);

        if ($record === null) {
            return Response::error("No {$link['record_type']} with ID [{$link['record_id']}] in this workspace. Nothing was changed.");
        }

        if ($user->cannot('update', $record)) {
            return Response::error("You do not have permission to update {$link['record_type']} [{$link['record_id']}]. Nothing was changed.");
        }

        return ['email' => $email, 'record' => $record];
    }
}
