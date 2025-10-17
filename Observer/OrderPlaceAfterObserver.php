<?php
/**
 * Order Place After Observer
 * Intercepts order placement event and publishes message to queue
 *
 * @category  Learning
 * @package   Learning_OrderEmailQueue
 */
declare(strict_types=1);

namespace Learning\OrderEmailQueue\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Learning\OrderEmailQueue\Model\Publisher\OrderEmailPublisher;
use Learning\OrderEmailQueue\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Class OrderPlaceAfterObserver
 * Observes checkout_submit_all_after event
 */
class OrderPlaceAfterObserver implements ObserverInterface
{
    /**
     * Configuration path for module enabled flag
     */
    private const XML_PATH_ENABLED = 'learning_order_email_queue/general/enabled';

    /**
     * @var OrderEmailPublisher
     */
    private OrderEmailPublisher $publisher;

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * OrderPlaceAfterObserver constructor
     *
     * @param OrderEmailPublisher $publisher
     * @param Logger $logger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        OrderEmailPublisher $publisher,
        Logger $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->publisher = $publisher;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Check if queue is enabled
        if (!$this->isEnabled()) {
            $this->logger->info('Order email queue is disabled. Email will be sent synchronously.');
            return;
        }

        try {
            /** @var OrderInterface $order */
            // checkout_submit_all_after passes order in the event
            $order = $observer->getEvent()->getOrder();

            // Validate order object
            if (!$order) {
                $this->logger->warning('No order object received in observer');
                return;
            }

            if (!$order->getEntityId()) {
                $this->logger->warning(
                    'Order object has no entity_id',
                    [
                        'increment_id' => $order->getIncrementId() ?? 'N/A',
                    ]
                );
                return;
            }

            // Prepare order data for queue
            $orderData = [
                'order_id' => (int)$order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'customer_email' => $order->getCustomerEmail(),
                'customer_name' => $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname(),
                'created_at' => $order->getCreatedAt(),
                'retry_count' => 0,
            ];

            // Publish message to queue
            $this->publisher->publish($orderData);

            $this->logger->info(
                'Order email message published to queue',
                [
                    'order_id' => $orderData['order_id'],
                    'increment_id' => $orderData['increment_id'],
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to publish order email message to queue: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Check if queue is enabled
     *
     * @return bool
     */
    private function isEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }
}
