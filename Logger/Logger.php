<?php
/**
 * Custom Logger
 * Logger for order email queue operations
 *
 * @category  Learning
 * @package   Learning_OrderEmailQueue
 */
declare(strict_types=1);

namespace Learning\OrderEmailQueue\Logger;

use Monolog\Logger as MonologLogger;

/**
 * Class Logger
 * Custom logger for order email queue
 */
class Logger extends MonologLogger
{
    /**
     * Logger name
     */
    public const LOGGER_NAME = 'OrderEmailQueueLogger';
}
