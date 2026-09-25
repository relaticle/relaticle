<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\CrmEntity;
use Illuminate\Database\Eloquent\Model;

trait OperatesOnCrmEntity
{
    abstract protected function entity(): CrmEntity;

    protected function entityType(): string
    {
        return $this->entity()->value;
    }

    protected function citationType(): string
    {
        return $this->entity()->value;
    }

    /** @return class-string<Model> */
    protected function modelClass(): string
    {
        return $this->entity()->model();
    }

    protected function entityLabel(): string
    {
        return $this->entity()->singularName();
    }

    protected function nameAttribute(): string
    {
        return $this->entity()->titleColumn();
    }
}
