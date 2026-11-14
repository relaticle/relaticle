<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLinkSuppression;

/**
 * @extends Factory<EmailLinkSuppression>
 */
final class EmailLinkSuppressionFactory extends Factory
{
    protected $model = EmailLinkSuppression::class;

    public function definition(): array
    {
        return [
            'email_id' => Email::factory(),
            'workspace_id' => fn (array $attributes): string => (string) Email::query()->withoutGlobalScopes()->whereKey($attributes['email_id'])->value('workspace_id'),
            'emailable_type' => (new Company)->getMorphClass(),
            'emailable_id' => fn (array $attributes): string => (string) Company::factory()->create(['workspace_id' => $attributes['workspace_id']])->getKey(),
        ];
    }
}
