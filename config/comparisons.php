<?php

declare(strict_types=1);

/**
 * Declares the launch set for the comparison-page engine. Every slug here
 * must have a matching entry in `resources/data/competitor-facts.php` —
 * `ComparisonController` and `AlternativesController` 404 for any slug not
 * listed below.
 *
 * @return array{
 *     compare: array<int, string>,
 *     alternatives: array<int, string>,
 * }
 */
return [

    /*
     * Rendered at /compare/relaticle-vs-{competitor}.
     */
    'compare' => ['twenty', 'espocrm'],

    /*
     * Rendered at /alternatives/{competitor}, framed for migration intent.
     */
    'alternatives' => ['attio', 'hubspot'],

];
