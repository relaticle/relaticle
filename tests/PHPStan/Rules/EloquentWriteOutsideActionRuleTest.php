<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\EloquentWriteOutsideActionRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<EloquentWriteOutsideActionRule>
 */
final class EloquentWriteOutsideActionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new EloquentWriteOutsideActionRule(
            reflectionProvider: $this->createReflectionProvider(),
            guardedNamespaces: ['App\Http\Controllers', 'App\Livewire'],
        );
    }

    public function test_flags_eloquent_writes_in_guarded_namespaces(): void
    {
        $expectedMessage = 'Eloquent write ->%s() in a UI/transport surface — route writes through an action class in app/Actions (see .ai/guidelines/relaticle/architecture.md).';

        $this->analyse([__DIR__.'/data/eloquent-write-in-controller.php'], [
            [sprintf($expectedMessage, 'create'), 9],
            [sprintf($expectedMessage, 'update'), 13],
            [sprintf($expectedMessage, 'delete'), 15],
            [sprintf($expectedMessage, 'save'), 17],
            [sprintf($expectedMessage, 'delete'), 19],
        ]);
    }

    public function test_allows_writes_outside_guarded_namespaces_and_reads_inside(): void
    {
        $this->analyse([__DIR__.'/data/eloquent-write-allowed.php'], []);
    }
}
