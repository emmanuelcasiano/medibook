<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Log\Logger;

/**
 * The `tap` class for config/logging.php. Laravel calls it once when a channel is built. The `single` and `daily` drivers
 * ignore a `processors` key, which is why a tap is needed. A `stack` copies the processors of its channels.
 */
final class MaskLogs
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new LogMasker);
    }
}
