<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\RelationAccessInPolicyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RelationAccessInPolicyRule>
 */
final class RelationAccessInPolicyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RelationAccessInPolicyRule(
            reflectionProvider: $this->createReflectionProvider(),
            guardedNamespaces: ['App\Policies'],
        );
    }

    public function test_flags_relation_access_on_the_authorized_record(): void
    {
        $expectedMessage = 'Policy resolves the `%s` relation on %s — authorize on the foreign key instead. A policy runs once per row, so this costs a query per row and throws once a query hydrates more than one row.';

        $this->analyse([__DIR__.'/data/relation-access-in-policy.php'], [
            [sprintf($expectedMessage, 'team', 'Company'), 15],
            [sprintf($expectedMessage, 'creator', 'Task'), 20],
            [sprintf($expectedMessage, 'team', 'Task'), 25],
            [sprintf($expectedMessage, 'team', 'Task'), 30],
        ]);
    }

    public function test_allows_foreign_key_checks_acting_user_relations_and_other_namespaces(): void
    {
        $this->analyse([__DIR__.'/data/relation-access-allowed.php'], []);
    }
}
