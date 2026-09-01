<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasTeam;
use App\Models\User;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class EmailTemplate extends Model
{
    /**
     * @use HasFactory<EmailTemplateFactory>
     */
    use HasFactory, HasTeam, HasUlids, SoftDeletes;

    protected static function newFactory(): EmailTemplateFactory
    {
        return EmailTemplateFactory::new();
    }

    protected $fillable = [
        'team_id',
        'created_by',
        'name',
        'subject',
        'body_html',
        'variables',
        'is_shared',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_shared' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
