<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\HardcodedUserFacingStringRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<HardcodedUserFacingStringRule>
 */
final class HardcodedUserFacingStringRuleTest extends RuleTestCase
{
    /**
     * Mirror the canonical allowlist from phpstan.neon's
     * services -> App\PHPStan\Rules\HardcodedUserFacingStringRule.arguments.guardedMethods.
     * The test below also asserts these stay in sync.
     */
    private const array GUARDED_METHODS = [
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

    public function test_guarded_methods_match_phpstan_config(): void
    {
        $configPath = dirname(__DIR__, 3).'/phpstan.neon';
        $config = file_get_contents($configPath);

        self::assertNotFalse($config, 'phpstan.neon must be readable');

        if (preg_match('/HardcodedUserFacingStringRule\b.*?guardedMethods:\s*((?:\s*-\s*\w+)+)/s', $config, $matches) !== 1) {
            self::fail('Could not locate HardcodedUserFacingStringRule.guardedMethods in phpstan.neon');
        }

        preg_match_all('/-\s*(\w+)/', $matches[1], $methodMatches);
        $configMethods = $methodMatches[1];

        sort($configMethods);
        $testMethods = self::GUARDED_METHODS;
        sort($testMethods);

        self::assertSame(
            $testMethods,
            $configMethods,
            'GUARDED_METHODS in this test must match guardedMethods in phpstan.neon',
        );
    }

    public function test_flags_hardcoded_label(): void
    {
        $this->analyse([__DIR__.'/data/hardcoded-label.php'], [
            [
                'Hardcoded user-facing string in ->label() — wrap in __() and add a key under lang/en/.',
                7,
            ],
        ]);
    }

    public function test_allows_translated_label(): void
    {
        $this->analyse([__DIR__.'/data/translated-label.php'], []);
    }
}
