<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags hardcoded user-facing strings used as the default value of
 * static class properties (e.g. Filament's $navigationLabel,
 * $navigationGroup). Static properties are evaluated at class load
 * before locale resolution, so __() doesn't work in them — the
 * property must be null and the matching getter must override with __().
 *
 * @implements Rule<Property>
 */
final readonly class HardcodedStaticPropertyRule implements Rule
{
    /**
     * @param  list<string>  $guardedProperties
     */
    public function __construct(
        private array $guardedProperties,
    ) {}

    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->isStatic()) {
            return [];
        }

        $errors = [];

        foreach ($node->props as $prop) {
            $name = $prop->name->toString();

            if (! in_array($name, $this->guardedProperties, true)) {
                continue;
            }

            if (! $prop->default instanceof String_) {
                continue;
            }

            $methodName = 'get'.ucfirst($name);

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Hardcoded user-facing string in $%s — set property to null and override %s() with __().',
                $name,
                $methodName,
            ))
                ->identifier('app.i18n.hardcodedStaticProperty')
                ->line($prop->getStartLine())
                ->build();
        }

        return $errors;
    }
}
