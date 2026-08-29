<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\HardcodedStaticPropertyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<HardcodedStaticPropertyRule>
 */
abstract class HardcodedStaticPropertyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new HardcodedStaticPropertyRule(
            guardedProperties: ['navigationLabel', 'navigationGroup', 'modelLabel', 'pluralModelLabel', 'breadcrumb'],
        );
    }
}

pest()->extend(HardcodedStaticPropertyRuleTest::class);

it('flags a hardcoded static property', function (): void {
    $this->analyse([__DIR__.'/data/hardcoded-static-nav.php'], [
        ['Hardcoded user-facing string in $navigationGroup: set property to null and override getNavigationGroup() with __().', 9],
    ]);
});

it('allows a null static property', function (): void {
    $this->analyse([__DIR__.'/data/null-static-nav.php'], []);
});
