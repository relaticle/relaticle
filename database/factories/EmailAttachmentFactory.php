<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;

/**
 * @extends Factory<EmailAttachment>
 */
final class EmailAttachmentFactory extends Factory
{
    protected $model = EmailAttachment::class;

    public function definition(): array
    {
        return [
            'email_id' => Email::factory(),
            'filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 10485760),
            'storage_path' => 'attachments/'.fake()->uuid().'.pdf',
            'is_inline' => false,
        ];
    }

    public function inline(): static
    {
        return $this->state(fn (): array => [
            'content_id' => 'cid-'.fake()->uuid(),
            'mime_type' => 'image/png',
            'filename' => 'image.png',
            'is_inline' => true,
        ]);
    }
}
