<?php
/**
 * Custom Log Handler
 * Handles logging to custom log file
 *
 * @category  Learning
 * @package   Learning_OrderEmailQueue
 */
declare(strict_types=1);

namespace Learning\OrderEmailQueue\Logger;

use Monolog\Logger as MonologLogger;
use Magento\Framework\Logger\Handler\Base;

/**
 * Class Handler
 * Custom handler for order email queue logging
 */
class Handler extends Base
{
    /**
     * Logging level
     *
     * @var int
     */
    protected $loggerType = MonologLogger::INFO;

    /**
     * Log file name
     *
     * @var string
     */
    protected $fileName = '/var/log/order_email_queue.log';
}
