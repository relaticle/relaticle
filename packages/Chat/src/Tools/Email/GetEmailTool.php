<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Concerns\ReportsValidationFailures;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;
use Relaticle\EmailIntegration\Support\EmailForAgent;

final readonly class GetEmailTool implements Tool
{
    use ReportsValidationFailures;

    public function __construct(
        private VisibleEmailsQuery $emails,
        private EmailForAgent $presenter,
    ) {}

    public function description(): string
    {
        return 'Read one synced email by id. `body_text` and `attachments` are returned only when `access` is full.'
            .' A long body is cut and flagged in `body_truncated`.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The email id, as returned by the list emails tool.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            /** @var array{id: string} $validated */
            $validated = $request->validate(['id' => ['required', 'string', 'max:64']]);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }

        $email = $this->emails->find($user, $validated['id']);
        $data = $email instanceof Email ? $this->presenter->detail($email, $user) : null;

        if ($data === null) {
            return (string) json_encode(['error' => 'Email not found.'], JSON_UNESCAPED_SLASHES);
        }

        return (string) json_encode([
            'data' => $data,
            'note' => ListEmailsTool::DATA_NOTE,
        ], JSON_UNESCAPED_SLASHES);
    }
}
