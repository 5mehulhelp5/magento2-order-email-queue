<?php
/**
 * Order Sender Plugin
 * Prevents immediate order email sending when queue is enabled
 *
 * @category  Learning
 * @package   Learning_OrderEmailQueue
 */
declare(strict_types=1);

namespace Learning\OrderEmailQueue\Plugin;

use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Learning\OrderEmailQueue\Logger\Logger;

/**
 * Plugin to intercept OrderSender::send() method
 */
class OrderSenderPlugin
{
    /**
     * Configuration path for module enabled flag
     */
    private const XML_PATH_ENABLED = 'learning_order_email_queue/general/enabled';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * OrderSenderPlugin constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Logger $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Around plugin for send method
     * Prevents email from being sent if queue is enabled
     *
     * @param OrderSender $subject
     * @param callable $proceed
     * @param Order $order
     * @param bool $forceSyncMode
     * @return bool
     */
    public function aroundSend(
        OrderSender $subject,
        callable $proceed,
        Order $order,
        bool $forceSyncMode = false
    ): bool {
        // If forceSyncMode is true, allow email to be sent (consumer processing)
        if ($forceSyncMode) {
            return $proceed($order, $forceSyncMode);
        }

        // Get store ID and cast to int
        $storeId = $order->getStoreId() ? (int)$order->getStoreId() : null;

        // Check if queue is enabled
        if ($this->isQueueEnabled($storeId)) {
            $this->logger->info(
                'Order email sending prevented - will be queued instead',
                [
                    'order_id' => $order->getEntityId(),
                    'increment_id' => $order->getIncrementId(),
                ]
            );

            // Return true to indicate email was "sent" (queued)
            // This prevents Magento from marking order as email not sent
            return true;
        }

        // If queue is disabled, allow normal email sending
        return $proceed($order, $forceSyncMode);
    }

    /**
     * Check if queue is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    private function isQueueEnabled(?int $storeId = null): bool
    {
        return (bool)$this->scopeConfig->getValue(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
