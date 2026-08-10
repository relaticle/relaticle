<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Contracts;

use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;

interface CalendarServiceInterface
{
    public function initialSync(): CalendarSyncResult;

    /**
     * @throws CalendarSyncTokenExpired when Google invalidates the syncToken (HTTP 410)
     */
    public function fetchDelta(string $syncToken): CalendarSyncResult;
}
