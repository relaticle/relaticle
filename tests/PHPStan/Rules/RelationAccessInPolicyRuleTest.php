<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\RelationAccessInPolicyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RelationAccessInPolicyRule>
 */
abstract class RelationAccessInPolicyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RelationAccessInPolicyRule(
            reflectionProvider: $this->createReflectionProvider(),
            guardedNamespaces: ['App\Policies'],
        );
    }
}

pest()->extend(RelationAccessInPolicyRuleTest::class);

it('flags relation access on the authorized record', function (): void {
    $expectedMessage = 'Policy resolves the `%s` relation on %s: authorize on the foreign key instead. A policy runs once per row, so this costs a query per row and throws once a query hydrates more than one row.';

    $this->analyse([__DIR__.'/data/relation-access-in-policy.php'], [
        [sprintf($expectedMessage, 'team', 'Company'), 15],
        [sprintf($expectedMessage, 'creator', 'Task'), 20],
        [sprintf($expectedMessage, 'team', 'Task'), 25],
        [sprintf($expectedMessage, 'team', 'Task'), 30],
    ]);
});

it('allows foreign key checks acting user relations and other namespaces', function (): void {
    $this->analyse([__DIR__.'/data/relation-access-allowed.php'], []);
});
