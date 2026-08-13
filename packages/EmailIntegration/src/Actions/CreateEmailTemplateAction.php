<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final readonly class CreateEmailTemplateAction
{
    /**
     * @param  array{name: string, subject: string, body_html: string, is_shared?: bool}  $data
     */
    public function execute(User $user, array $data): EmailTemplate
    {
        return EmailTemplate::query()->create([
            'team_id' => $user->current_team_id,
            'created_by' => $user->getKey(),
            'name' => $data['name'],
            'subject' => $data['subject'],
            'body_html' => $data['body_html'],
            'is_shared' => $data['is_shared'] ?? false,
        ]);
    }
}
