<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\AutomatedSenderOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $email_address
 * @property string|null $user_id
 * @property CarbonImmutable $created_at
 */
#[Fillable(['workspace_id', 'email_address', 'user_id'])]
final class AutomatedSenderOverride extends Model
{
    /**
     * @use HasFactory<AutomatedSenderOverrideFactory>
     */
    use HasFactory, HasUlids, HasWorkspace;

    public const UPDATED_AT = null;

    protected static function newFactory(): AutomatedSenderOverrideFactory
    {
        return AutomatedSenderOverrideFactory::new();
    }

    /** @return Attribute<never, string> */
    protected function emailAddress(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::lower($value));
    }
}
