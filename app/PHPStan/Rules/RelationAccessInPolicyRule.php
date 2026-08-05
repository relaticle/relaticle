<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Keeps authorization off the authorized record's relations.
 *
 * A policy runs once per row, so resolving a relation there costs a query per
 * row — and throws outright once a query hydrates more than one row, because
 * Eloquent only arms its strict lazy-loading guard at that point
 * (Builder::hydrate sets it when count($items) > 1). That makes the defect
 * invisible in any single-record test and fatal on a populated table.
 *
 * Relation access on the acting user is exempt: policies always receive the
 * authenticated user, which is hydrated on its own and therefore never carries
 * the guard.
 *
 * Nullsafe access (`$record?->team`) is covered too: PHPStan desugars it into a
 * plain property fetch on the non-null branch.
 *
 * @implements Rule<PropertyFetch>
 */
final readonly class RelationAccessInPolicyRule implements Rule
{
    /**
     * @param  list<string>  $guardedNamespaces  namespaces where relation access is forbidden
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private array $guardedNamespaces,
    ) {}

    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->inGuardedNamespace($scope)) {
            return [];
        }

        if (! $node->name instanceof Identifier) {
            return [];
        }

        $receiver = $scope->getType($node->var);

        if (! new ObjectType(Model::class)->isSuperTypeOf($receiver)->yes()) {
            return [];
        }

        if (new ObjectType(Authenticatable::class)->isSuperTypeOf($receiver)->yes()) {
            return [];
        }

        $property = $node->name->toString();

        foreach ($receiver->getObjectClassNames() as $className) {
            if (! $this->isRelation($className, $property)) {
                continue;
            }

            return [$this->error($property, $className)];
        }

        return [];
    }

    private function isRelation(string $className, string $property): bool
    {
        if (! $this->reflectionProvider->hasClass($className)) {
            return false;
        }

        $classReflection = $this->reflectionProvider->getClass($className);

        if (! $classReflection->hasNativeMethod($property)) {
            return false;
        }

        $returnType = $classReflection->getNativeMethod($property)->getOnlyVariant()->getReturnType();

        return new ObjectType(Relation::class)->isSuperTypeOf($returnType)->yes();
    }

    private function inGuardedNamespace(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null) {
            return false;
        }

        return array_any($this->guardedNamespaces, fn (string $guarded): bool => $namespace === $guarded || str_starts_with($namespace, $guarded.'\\'));
    }

    private function error(string $property, string $className): IdentifierRuleError
    {
        $shortName = basename(str_replace('\\', '/', $className));

        return RuleErrorBuilder::message(
            "Policy resolves the `{$property}` relation on {$shortName} — authorize on the foreign key instead. A policy runs once per row, so this costs a query per row and throws once a query hydrates more than one row."
        )
            ->identifier('app.architecture.relationAccessInPolicy')
            ->build();
    }
}
