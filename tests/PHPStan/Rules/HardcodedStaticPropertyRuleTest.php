<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\HardcodedStaticPropertyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<HardcodedStaticPropertyRule>
 */
final class HardcodedStaticPropertyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new HardcodedStaticPropertyRule(
            guardedProperties: ['navigationLabel', 'navigationGroup', 'modelLabel', 'pluralModelLabel', 'breadcrumb'],
        );
    }

    public function test_flags_hardcoded_static_property(): void
    {
        $this->analyse([__DIR__.'/data/hardcoded-static-nav.php'], [
            ['Hardcoded user-facing string in $navigationGroup — set property to null and override getNavigationGroup() with __().', 9],
        ]);
    }

    public function test_allows_null_static_property(): void
    {
        $this->analyse([__DIR__.'/data/null-static-nav.php'], []);
    }
}
