<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Enforces the project's core write-path convention: all create/update/delete
 * operations go through action classes in app/Actions. Flags Eloquent write
 * calls (on models, eloquent builders, and relations) made from UI and
 * transport surfaces — controllers, MCP tools, Livewire components, Filament
 * resources, chat tools — where business logic must not live.
 *
 * Existing violations are grandfathered via path-scoped ignores in
 * phpstan.neon; new ones fail analysis.
 *
 * @implements Rule<CallLike>
 */
final readonly class EloquentWriteOutsideActionRule implements Rule
{
    /**
     * @param  list<string>  $guardedNamespaces  namespaces where Eloquent writes are forbidden
     * @param  list<string>  $writeMethods
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private array $guardedNamespaces,
        private array $writeMethods = [
            'create', 'createQuietly', 'forceCreate', 'createMany', 'createManyQuietly',
            'save', 'saveQuietly', 'push',
            'update', 'updateQuietly', 'updateOrCreate', 'firstOrCreate',
            'delete', 'deleteQuietly', 'forceDelete', 'destroy', 'restore',
            'insert', 'upsert',
            'attach', 'detach', 'sync', 'syncWithoutDetaching', 'toggle',
        ],
    ) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->inGuardedNamespace($scope)) {
            return [];
        }

        if ($node instanceof MethodCall) {
            return $this->processMethodCall($node, $scope);
        }

        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processMethodCall(MethodCall $node, Scope $scope): array
    {
        $method = $this->calledMethod($node->name);

        if ($method === null) {
            return [];
        }

        $receiver = $scope->getType($node->var);

        $isWriteTarget = new ObjectType(Model::class)->isSuperTypeOf($receiver)->yes()
            || new ObjectType(Builder::class)->isSuperTypeOf($receiver)->yes()
            || new ObjectType(Relation::class)->isSuperTypeOf($receiver)->yes();

        if (! $isWriteTarget) {
            return [];
        }

        return [$this->error($method)];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processStaticCall(StaticCall $node): array
    {
        $method = $this->calledMethod($node->name);

        if ($method === null) {
            return [];
        }

        if (! $node->class instanceof Name) {
            return [];
        }

        $className = $node->class->toString();

        if (! $this->reflectionProvider->hasClass($className)) {
            return [];
        }

        if (! $this->reflectionProvider->getClass($className)->is(Model::class)) {
            return [];
        }

        return [$this->error($method)];
    }

    private function calledMethod(Node $name): ?string
    {
        if (! $name instanceof Identifier) {
            return null;
        }

        $method = $name->toString();

        return in_array($method, $this->writeMethods, true) ? $method : null;
    }

    private function inGuardedNamespace(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null) {
            return false;
        }

        return array_any($this->guardedNamespaces, fn (string $guarded): bool => $namespace === $guarded || str_starts_with($namespace, $guarded.'\\'));
    }

    private function error(string $method): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            "Eloquent write ->{$method}() in a UI/transport surface — route writes through an action class in app/Actions (see .ai/guidelines/relaticle/architecture.md)."
        )
            ->identifier('app.architecture.eloquentWriteOutsideAction')
            ->build();
    }
}
