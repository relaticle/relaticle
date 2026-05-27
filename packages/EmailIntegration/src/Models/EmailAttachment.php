<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use Database\Factories\EmailAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $storage_path
 * @property string|null $provider_attachment_id Gmail attachment ID (can exceed 255 chars, stored as text)
 */
#[WithoutTimestamps]
final class EmailAttachment extends Model
{
    /**
     * @use HasFactory<EmailAttachmentFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): EmailAttachmentFactory
    {
        return EmailAttachmentFactory::new();
    }

    protected $fillable = [
        'email_id',
        'filename',
        'mime_type',
        'size',
        'storage_path',
        'content_id',
        'provider_attachment_id',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    /**
     * @return BelongsTo<Email, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
