<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags string-literal first arguments to user-facing builder methods (e.g.
 * Filament's ->label(), ->placeholder()) so they are wrapped in __() and
 * sourced from lang/* translation files.
 *
 * @implements Rule<MethodCall>
 */
final readonly class HardcodedUserFacingStringRule implements Rule
{
    /**
     * @param  list<string>  $guardedMethods
     */
    public function __construct(
        private array $guardedMethods,
    ) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->toString();

        if (! in_array($methodName, $this->guardedMethods, true)) {
            return [];
        }

        $firstArg = $node->args[0] ?? null;

        if (! $firstArg instanceof Arg) {
            return [];
        }

        if (! $firstArg->value instanceof String_) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Hardcoded user-facing string in ->%s() — wrap in __() and add a key under lang/en/.',
                $methodName,
            ))
                ->identifier('app.i18n.hardcodedUserFacingString')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
