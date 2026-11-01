<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $conversation_key
 * @property int $message_count
 * @property CarbonImmutable $first_message_at
 * @property CarbonImmutable $last_message_at
 * @property CarbonImmutable|null $last_external_inbound_at
 * @property CarbonImmutable|null $last_member_outbound_at
 * @property bool $has_attachments
 * @property bool $is_automated
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'workspace_id',
    'conversation_key',
    'message_count',
    'first_message_at',
    'last_message_at',
    'last_external_inbound_at',
    'last_member_outbound_at',
    'has_attachments',
    'is_automated',
])]
final class EmailConversation extends Model
{
    use HasFactory;
    use HasUlids, HasWorkspace;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_count' => 'integer',
            'first_message_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_external_inbound_at' => 'datetime',
            'last_member_outbound_at' => 'datetime',
            'has_attachments' => 'boolean',
            'is_automated' => 'boolean',
        ];
    }
}
