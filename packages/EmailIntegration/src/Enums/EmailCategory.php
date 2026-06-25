<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Relaticle\EmailIntegration\Services\EmailClassifier;

/**
 * Canonical vocabulary for email classification.
 *
 * Produced by {@see EmailClassifier} from deterministic rules during sync.
 * Values are stored verbatim on `email_labels.label`, so they double as the
 * on-the-wire contract — keep them stable.
 */
enum EmailCategory: string
{
    case Scheduling = 'Scheduling';
    case Marketing = 'Marketing';
    case Invoice = 'Invoice';
    case Support = 'Support';
    case Sales = 'Sales';
    case Personal = 'Personal';
    case Other = 'Other';
}
