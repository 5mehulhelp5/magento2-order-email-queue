<?php
/**
 * Queue Status CLI Command
 * Displays queue status and statistics
 *
 * @category  Learning
 * @package   Learning_OrderEmailQueue
 */
declare(strict_types=1);

namespace Learning\OrderEmailQueue\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Magento\Framework\Amqp\Config as AmqpConfig;

/**
 * Class QueueStatusCommand
 * CLI command to display queue status
 */
class QueueStatusCommand extends Command
{
    /**
     * Queue names
     */
    private const MAIN_QUEUE = 'order.email.queue';
    private const DLQ_QUEUE = 'order.email.queue.dead';
    private const CONSUMER_NAME = 'orderEmailConsumer';

    /**
     * @var ConsumerConfig
     */
    private ConsumerConfig $consumerConfig;

    /**
     * @var AmqpConfig
     */
    private AmqpConfig $amqpConfig;

    /**
     * QueueStatusCommand constructor
     *
     * @param ConsumerConfig $consumerConfig
     * @param AmqpConfig $amqpConfig
     * @param string|null $name
     */
    public function __construct(
        ConsumerConfig $consumerConfig,
        AmqpConfig $amqpConfig,
        string $name = null
    ) {
        parent::__construct($name);
        $this->consumerConfig = $consumerConfig;
        $this->amqpConfig = $amqpConfig;
    }

    /**
     * Configure command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('learning:queue:status')
            ->setDescription('Display order email queue status and statistics');

        parent::configure();
    }

    /**
     * Execute command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Order Email Queue Status</info>');
        $output->writeln('');

        try {
            // Get consumer configuration
            $consumer = $this->consumerConfig->getConsumer(self::CONSUMER_NAME);

            // Display consumer information
            $this->displayConsumerInfo($output, $consumer);

            // Display queue information
            $this->displayQueueInfo($output);

            $output->writeln('');
            $output->writeln('<comment>Note: For detailed queue statistics, use RabbitMQ management tools:</comment>');
            $output->writeln('<comment>  rabbitmqctl list_queues name messages consumers</comment>');
            $output->writeln('<comment>  Or access RabbitMQ Management UI at http://localhost:15672</comment>');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error retrieving queue status: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * Display consumer information
     *
     * @param OutputInterface $output
     * @param array $consumer
     * @return void
     */
    private function displayConsumerInfo(OutputInterface $output, array $consumer): void
    {
        $output->writeln('<info>Consumer Configuration:</info>');

        $table = new Table($output);
        $table->setHeaders(['Property', 'Value']);
        $table->addRows([
            ['Consumer Name', self::CONSUMER_NAME],
            ['Queue', $consumer['queue'] ?? 'N/A'],
            ['Connection', $consumer['connection'] ?? 'N/A'],
            ['Max Messages', $consumer['max_messages'] ?? 'N/A'],
            ['Handler', $consumer['handlers'][0]['type'] ?? 'N/A']
        ]);
        $table->render();

        $output->writeln('');
    }

    /**
     * Display queue information
     *
     * @param OutputInterface $output
     * @return void
     */
    private function displayQueueInfo(OutputInterface $output): void
    {
        $output->writeln('<info>Queue Information:</info>');

        $table = new Table($output);
        $table->setHeaders(['Queue Name', 'Type', 'Status']);
        $table->addRows([
            [self::MAIN_QUEUE, 'Main Queue', '<info>Active</info>'],
            [self::DLQ_QUEUE, 'Dead Letter Queue', '<info>Active</info>']
        ]);
        $table->render();

        $output->writeln('');
        $output->writeln('<comment>How to check queue depth:</comment>');
        $output->writeln('  1. Using RabbitMQ CLI:');
        $output->writeln('     rabbitmqctl list_queues name messages');
        $output->writeln('  2. Using RabbitMQ Management UI:');
        $output->writeln('     http://localhost:15672/#/queues');
        $output->writeln('  3. Check consumer status:');
        $output->writeln('     ps aux | grep "queue:consumers:start orderEmailConsumer"');
    }
}
