<?php

declare(strict_types=1);

use Relaticle\ImportWizard\Support\ColumnMatcher;

describe('ColumnMatcher', function (): void {
    beforeEach(function (): void {
        $this->matcher = new ColumnMatcher;
    });

    it('normalizes strings', function (string $input, string $expected): void {
        expect($this->matcher->normalize($input))->toBe($expected);
    })->with([
        'lowercases' => ['COMPANY', 'company'],
        'spaces to underscores' => ['Company Name', 'company_name'],
        'dashes to underscores' => ['company-name', 'company_name'],
        'dots to underscores' => ['company.name', 'company_name'],
        'collapses multiple spaces' => ['company  name', 'company_name'],
        'collapses multiple underscores' => ['company__name', 'company_name'],
        'trims whitespace' => ['  company  ', 'company'],
    ]);

    it('finds matching headers', function (array $headers, array $guesses, ?string $expected): void {
        expect($this->matcher->findMatchingHeader($headers, $guesses))->toBe($expected);
    })->with([
        'exact match' => [['name', 'email'], ['name'], 'name'],
        'different separators' => [['Company Name', 'email'], ['company_name'], 'Company Name'],
        'case-insensitive' => [['COMPANY', 'email'], ['company'], 'COMPANY'],
        'preserves original casing' => [['Company Name', 'Email'], ['company_name'], 'Company Name'],
        'no match returns null' => [['foo', 'bar'], ['company_name'], null],
        'first header wins' => [['name', 'company_name'], ['company_name', 'name'], 'name'],
        'HubSpot columns' => [['Associated Company', 'Deal Name'], ['associated_company'], 'Associated Company'],
        'Salesforce columns' => [['Account Name', 'Opportunity Name'], ['account_name'], 'Account Name'],
        'singular to plural' => [['Emails', 'Phones'], ['email'], 'Emails'],
        'plural to singular' => [['Email', 'Phone'], ['emails'], 'Email'],
        'complex plurals' => [['Companies', 'Opportunities'], ['company'], 'Companies'],
    ]);
});
