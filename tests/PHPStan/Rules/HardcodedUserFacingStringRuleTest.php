<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\HardcodedUserFacingStringRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<HardcodedUserFacingStringRule>
 */
abstract class HardcodedUserFacingStringRuleTest extends RuleTestCase
{
    /**
     * Mirror the canonical allowlist from phpstan.neon's
     * services -> App\PHPStan\Rules\HardcodedUserFacingStringRule.arguments.guardedMethods.
     * The test below also asserts these stay in sync.
     */
    public const array GUARDED_METHODS = [
        'label',
        'placeholder',
        'helperText',
        'heading',
        'description',
        'modalHeading',
        'emptyStateHeading',
        'emptyStateDescription',
        'subject',
        'title',
    ];

    protected function getRule(): Rule
    {
        return new HardcodedUserFacingStringRule(
            guardedMethods: self::GUARDED_METHODS,
        );
    }
}

pest()->extend(HardcodedUserFacingStringRuleTest::class);

it('keeps guarded methods aligned with PHPStan configuration', function (): void {
    $configPath = dirname(__DIR__, 3).'/phpstan.neon';
    $config = file_get_contents($configPath);

    expect($config)->not->toBeFalse();

    if (preg_match('/HardcodedUserFacingStringRule\b.*?guardedMethods:\s*((?:\s*-\s*\w+)+)/s', $config, $matches) !== 1) {
        $this->fail('Could not locate HardcodedUserFacingStringRule.guardedMethods in phpstan.neon');
    }

    preg_match_all('/-\s*(\w+)/', $matches[1], $methodMatches);
    $configMethods = $methodMatches[1];

    sort($configMethods);
    $testMethods = HardcodedUserFacingStringRuleTest::GUARDED_METHODS;
    sort($testMethods);

    expect($configMethods)->toBe(
        $testMethods,
        'GUARDED_METHODS in this test must match guardedMethods in phpstan.neon',
    );
});

it('flags a hardcoded label', function (): void {
    $this->analyse([__DIR__.'/data/hardcoded-label.php'], [
        [
            'Hardcoded user-facing string in ->label(): wrap in __() and add a key under lang/en/.',
            7,
        ],
    ]);
});

it('allows a translated label', function (): void {
    $this->analyse([__DIR__.'/data/translated-label.php'], []);
});
