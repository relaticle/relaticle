<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use PHPUnit\Framework\Attributes\Group;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class Splits
{
    public const array NAMES = ['train', 'test', 'guard'];

    private const string CASE_NAMESPACE = 'P\\Tests\\Evals\\';

    public static function assertComplete(): void
    {
        self::assertGrouped(self::discover());
    }

    /**
     * @param  array<string, list<string>>  $groupsByCase
     */
    public static function assertGrouped(array $groupsByCase): void
    {
        foreach ($groupsByCase as $case => $groups) {
            $splits = array_values(array_intersect($groups, self::NAMES));

            if (count($splits) !== 1) {
                throw new RuntimeException(sprintf(
                    'Eval case [%s] needs exactly one of [%s], found %s',
                    $case,
                    implode(', ', self::NAMES),
                    $splits === [] ? 'none' : implode(' and ', $splits),
                ));
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function discover(): array
    {
        $cases = [];

        foreach (get_declared_classes() as $class) {
            if (! str_starts_with($class, self::CASE_NAMESPACE)) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class === $class && ! $method->isStatic() && str_starts_with($method->name, '__pest_evaluable_')) {
                    $cases[$class.'::'.$method->name] = array_map(
                        static fn (ReflectionAttribute $attribute): string => $attribute->newInstance()->name(),
                        $method->getAttributes(Group::class),
                    );
                }
            }
        }

        return $cases;
    }
}
