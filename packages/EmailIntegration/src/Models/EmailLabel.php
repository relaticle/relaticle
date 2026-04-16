<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use Database\Factories\EmailLabelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailLabel extends Model
{
    /**
     * @use HasFactory<EmailLabelFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): EmailLabelFactory
    {
        return EmailLabelFactory::new();
    }

    public $timestamps = false;

    protected $fillable = [
        'email_id',
        'label',
        'source',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Email, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
