<?php
/**
 * Order Email Publisher
 * Publishes order email messages to the queue
 *
 * @category  Learning
 * @package   Learning_OrderEmailQueue
 */
declare(strict_types=1);

namespace Learning\OrderEmailQueue\Model\Publisher;

use Magento\Framework\MessageQueue\PublisherInterface;
use Learning\OrderEmailQueue\Logger\Logger;

/**
 * Class OrderEmailPublisher
 * Handles publishing messages to the order email queue
 */
class OrderEmailPublisher
{
    /**
     * Topic name for order email messages
     */
    private const TOPIC_NAME = 'order.email.send';

    /**
     * @var PublisherInterface
     */
    private PublisherInterface $publisher;

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * OrderEmailPublisher constructor
     *
     * @param PublisherInterface $publisher
     * @param Logger $logger
     */
    public function __construct(
        PublisherInterface $publisher,
        Logger $logger
    ) {
        $this->publisher = $publisher;
        $this->logger = $logger;
    }

    /**
     * Publish order data to queue
     *
     * @param array $orderData
     * @return void
     * @throws \Exception
     */
    public function publish(array $orderData): void
    {
        try {
            // Convert array to JSON string
            $message = json_encode($orderData, JSON_THROW_ON_ERROR);

            // Publish to queue
            $this->publisher->publish(self::TOPIC_NAME, $message);

            $this->logger->info(
                'Message published successfully',
                [
                    'topic' => self::TOPIC_NAME,
                    'order_id' => $orderData['order_id'] ?? 'unknown'
                ]
            );
        } catch (\JsonException $e) {
            $this->logger->error(
                'Failed to encode order data to JSON: ' . $e->getMessage(),
                ['order_data' => $orderData]
            );
            throw new \Exception('Failed to encode order data: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to publish message to queue: ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw $e;
        }
    }
}
