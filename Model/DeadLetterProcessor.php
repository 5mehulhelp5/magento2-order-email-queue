<?php
/**
 * Dead Letter Queue Processor
 * Processes messages from the dead letter queue
 *
 * @category  Learning
 * @package   Learning_OrderEmailQueue
 */
declare(strict_types=1);

namespace Learning\OrderEmailQueue\Model;

use Learning\OrderEmailQueue\Logger\Logger;
use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Class DeadLetterProcessor
 * Consumer handler for processing dead letter queue messages
 */
class DeadLetterProcessor
{
    /**
     * Main queue topic
     */
    private const MAIN_TOPIC = 'order.email.send';

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * @var PublisherInterface
     */
    private PublisherInterface $publisher;

    /**
     * DeadLetterProcessor constructor
     *
     * @param Logger $logger
     * @param PublisherInterface $publisher
     */
    public function __construct(
        Logger $logger,
        PublisherInterface $publisher
    ) {
        $this->logger = $logger;
        $this->publisher = $publisher;
    }

    /**
     * Process message from dead letter queue
     *
     * @param string $message
     * @return void
     */
    public function process(string $message): void
    {
        try {
            $orderData = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

            $orderId = $orderData['order_id'] ?? 'unknown';

            $this->logger->info(
                'Processing message from dead letter queue',
                [
                    'order_id' => $orderId,
                    'message' => $message,
                ]
            );

            // Log the failed message for manual review
            $this->logger->warning(
                'Message found in dead letter queue - manual intervention required',
                [
                    'order_id' => $orderId,
                    'increment_id' => $orderData['increment_id'] ?? 'unknown',
                    'customer_email' => $orderData['customer_email'] ?? 'unknown',
                    'retry_count' => $orderData['retry_count'] ?? 0,
                ]
            );

            // Option: Uncomment to automatically requeue the message
            // $this->requeue($orderData);
        } catch (\JsonException $e) {
            $this->logger->error(
                'Failed to decode DLQ message JSON: ' . $e->getMessage(),
                ['message' => $message]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error processing DLQ message: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Requeue message back to main queue
     *
     * @param array $orderData
     * @return void
     */
    private function requeue(array $orderData): void
    {
        try {
            // Reset retry count
            $orderData['retry_count'] = 0;

            $message = json_encode($orderData, JSON_THROW_ON_ERROR);
            $this->publisher->publish(self::MAIN_TOPIC, $message);

            $this->logger->info(
                'Message requeued from DLQ to main queue',
                [
                    'order_id' => $orderData['order_id'] ?? 'unknown',
                    'topic' => self::MAIN_TOPIC,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to requeue message from DLQ: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
