<?php

declare(strict_types=1);

use Relaticle\Chat\Tools\Concerns\NormalizesToolInput;

$subject = new class
{
    use NormalizesToolInput;

    public function dn(array $v): array
    {
        return $this->dropNull($v);
    }

    public function coerce(mixed $v): array
    {
        return $this->coerceIdList($v);
    }
};

it('drops only null, preserving falsy-but-valid values', function () use ($subject): void {
    expect($subject->dn(['a' => '0', 'b' => 0, 'c' => false, 'd' => null, 'e' => '']))
        ->toBe(['a' => '0', 'b' => 0, 'c' => false, 'e' => '']);
});

it('coerces a scalar id into a single-element list', function () use ($subject): void {
    expect($subject->coerce('01ABC'))->toBe(['01ABC']);
    expect($subject->coerce(['01ABC', '', '01DEF', 5]))->toBe(['01ABC', '01DEF']);
    expect($subject->coerce(null))->toBe([]);
});
