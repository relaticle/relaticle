<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\EloquentWriteOutsideActionRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<EloquentWriteOutsideActionRule>
 */
abstract class EloquentWriteOutsideActionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new EloquentWriteOutsideActionRule(
            reflectionProvider: $this->createReflectionProvider(),
            guardedNamespaces: ['App\Http\Controllers', 'App\Livewire'],
        );
    }
}

pest()->extend(EloquentWriteOutsideActionRuleTest::class);

it('flags eloquent writes in guarded namespaces', function (): void {
    $expectedMessage = 'Eloquent write ->%s() in a UI/transport surface: route writes through an action class in app/Actions (see .ai/guidelines/relaticle/architecture.md).';

    $this->analyse([__DIR__.'/data/eloquent-write-in-controller.php'], [
        [sprintf($expectedMessage, 'create'), 9],
        [sprintf($expectedMessage, 'update'), 13],
        [sprintf($expectedMessage, 'delete'), 15],
        [sprintf($expectedMessage, 'save'), 17],
        [sprintf($expectedMessage, 'delete'), 19],
    ]);
});

it('allows writes outside guarded namespaces and reads inside', function (): void {
    $this->analyse([__DIR__.'/data/eloquent-write-allowed.php'], []);
});
