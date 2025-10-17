<?php
/**
 * Email Processor (Consumer)
 * Processes messages from the queue and sends order confirmation emails
 *
 * @category  Learning
 * @package   Learning_OrderEmailQueue
 */
declare(strict_types=1);

namespace Learning\OrderEmailQueue\Model;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Learning\OrderEmailQueue\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Class EmailProcessor
 * Consumer handler for processing order email messages
 */
class EmailProcessor
{
    /**
     * Configuration paths
     */
    private const XML_PATH_MAX_RETRIES = 'learning_order_email_queue/general/max_retry_attempts';
    private const XML_PATH_SIMULATE_FAILURES = 'learning_order_email_queue/testing/simulate_failures';

    /**
     * Dead letter queue topic
     */
    private const DLQ_TOPIC = 'order.email.send.dead';

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var OrderSender
     */
    private OrderSender $orderSender;

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var PublisherInterface
     */
    private PublisherInterface $publisher;

    /**
     * EmailProcessor constructor
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param Logger $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param PublisherInterface $publisher
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        PublisherInterface $publisher
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->publisher = $publisher;
    }

    /**
     * Process message from queue
     *
     * @param string $message
     * @return void
     * @throws \Exception
     */
    public function process(string $message): void
    {
        try {
            // Decode JSON message
            $orderData = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

            $orderId = $orderData['order_id'] ?? null;
            $retryCount = $orderData['retry_count'] ?? 0;

            if (!$orderId) {
                $this->logger->error('Invalid message: missing order_id', ['message' => $message]);
                return;
            }

            $this->logger->info(
                'Processing order email message',
                [
                    'order_id' => $orderId,
                    'retry_count' => $retryCount
                ]
            );

            // Load order
            $order = $this->orderRepository->get($orderId);

            if (!$order->getEntityId()) {
                $this->logger->error('Order not found', ['order_id' => $orderId]);
                return;
            }

            // Simulate random failures for testing (if enabled)
            if ($this->shouldSimulateFailure()) {
                throw new \Exception('Simulated failure for testing purposes');
            }

            // Attempt to send email
            // Pass true for forceSyncMode to bypass queue plugin and send immediately
            $emailSent = $this->orderSender->send($order, true);

            if (!$emailSent) {
                throw new \Exception('Failed to send order confirmation email');
            }

            // Success
            $this->logger->info(
                'Order confirmation email sent successfully',
                [
                    'order_id' => $orderId,
                    'increment_id' => $orderData['increment_id'] ?? 'unknown',
                    'customer_email' => $orderData['customer_email'] ?? 'unknown'
                ]
            );
        } catch (\JsonException $e) {
            $this->logger->error(
                'Failed to decode message JSON: ' . $e->getMessage(),
                ['message' => $message]
            );
            // Don't retry for invalid JSON
        } catch (\Exception $e) {
            $this->handleFailure($message, $orderData ?? [], $e);
        }
    }

    /**
     * Handle processing failure with retry logic
     *
     * @param string $originalMessage
     * @param array $orderData
     * @param \Exception $exception
     * @return void
     * @throws \Exception
     */
    private function handleFailure(string $originalMessage, array $orderData, \Exception $exception): void
    {
        $retryCount = ($orderData['retry_count'] ?? 0) + 1;
        $maxRetries = $this->getMaxRetries();
        $orderId = $orderData['order_id'] ?? 'unknown';

        $this->logger->warning(
            sprintf(
                'Failed to process order email (attempt %d/%d): %s',
                $retryCount,
                $maxRetries,
                $exception->getMessage()
            ),
            [
                'order_id' => $orderId,
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries
            ]
        );

        if ($retryCount < $maxRetries) {
            // Update retry count and requeue
            $orderData['retry_count'] = $retryCount;

            // Implement exponential backoff delay
            $backoffDelay = pow(2, $retryCount);
            $this->logger->info(
                "Message will be requeued with exponential backoff delay: {$backoffDelay} seconds",
                ['order_id' => $orderId]
            );

            // Throw exception to trigger requeue
            throw new \Exception(
                sprintf(
                    'Requeuing message for retry (attempt %d/%d)',
                    $retryCount,
                    $maxRetries
                )
            );
        } else {
            // Max retries exceeded - move to dead letter queue
            $this->moveToDeadLetterQueue($orderData);

            $this->logger->error(
                'Max retry attempts exceeded. Message moved to dead letter queue.',
                [
                    'order_id' => $orderId,
                    'retry_count' => $retryCount,
                    'final_error' => $exception->getMessage()
                ]
            );
        }
    }

    /**
     * Move message to dead letter queue
     *
     * @param array $orderData
     * @return void
     */
    private function moveToDeadLetterQueue(array $orderData): void
    {
        try {
            $message = json_encode($orderData, JSON_THROW_ON_ERROR);
            $this->publisher->publish(self::DLQ_TOPIC, $message);

            $this->logger->info(
                'Message published to dead letter queue',
                [
                    'order_id' => $orderData['order_id'] ?? 'unknown',
                    'dlq_topic' => self::DLQ_TOPIC
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to publish message to dead letter queue: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Get max retry attempts from configuration
     *
     * @return int
     */
    private function getMaxRetries(): int
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_MAX_RETRIES,
            ScopeInterface::SCOPE_STORE
        ) ?: 3;
    }

    /**
     * Check if should simulate failure for testing
     *
     * @return bool
     */
    private function shouldSimulateFailure(): bool
    {
        $simulateFailures = (bool)$this->scopeConfig->getValue(
            self::XML_PATH_SIMULATE_FAILURES,
            ScopeInterface::SCOPE_STORE
        );

        if (!$simulateFailures) {
            return false;
        }

        // 20% failure rate
        return rand(1, 100) <= 20;
    }
}
