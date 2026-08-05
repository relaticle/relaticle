<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Sluggable\Actions\GenerateSlugAction;
use Spatie\Sluggable\SlugOptions;

/**
 * Treats reserved slugs as already taken so the generator suffixes past them,
 * keeping auto-generated slugs from colliding with top-level route segments.
 */
final class ReservedSlugAwareGenerateSlugAction extends GenerateSlugAction
{
    /**
     * @param  array<int, string>  $reservedSlugs
     */
    public function __construct(private readonly array $reservedSlugs) {}

    /**
     * The bulk-variant query in the parent decides "is this taken?" purely from
     * existing rows, so a reserved-but-unused slug would slip through. Reserved
     * slugs take the iterative path, which consults otherRecordExistsWithSlug().
     */
    public function makeUnique(string $slug, Model $model, SlugOptions $options): string
    {
        if ($this->isReserved($slug)) {
            return $this->makeUniqueIterative($slug, $model, $options);
        }

        return parent::makeUnique($slug, $model, $options);
    }

    protected function otherRecordExistsWithSlug(string $slug, Model $model, SlugOptions $options): bool
    {
        if ($this->isReserved($slug)) {
            return true;
        }

        return parent::otherRecordExistsWithSlug($slug, $model, $options);
    }

    private function isReserved(string $slug): bool
    {
        return in_array($slug, $this->reservedSlugs, true);
    }
}
