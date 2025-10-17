<?php
/**
 * Process Dead Letter Queue Command
 *
 * @category  Learning
 * @package   Learning_OrderEmailQueue
 */
declare(strict_types=1);

namespace Learning\OrderEmailQueue\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Console\Cli;

/**
 * Class ProcessDlqCommand
 * CLI command to process dead letter queue
 */
class ProcessDlqCommand extends Command
{
    /**
     * Command name
     */
    private const COMMAND_NAME = 'learning:queue:process-dlq';

    /**
     * Configure command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Process messages from the dead letter queue')
            ->addOption(
                'max-messages',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of messages to process',
                10
            );

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
        $maxMessages = (int)$input->getOption('max-messages');

        $output->writeln('<info>Starting to process dead letter queue...</info>');
        $output->writeln('Consumer: orderEmailDeadLetterConsumer');
        $output->writeln("Max messages: {$maxMessages}");
        $output->writeln('');

        try {
            $output->writeln('<comment>Executing consumer...</comment>');
            $output->writeln('');

            // Execute the consumer
            $command = sprintf(
                'bin/magento queue:consumers:start orderEmailDeadLetterConsumer --max-messages=%d',
                $maxMessages
            );

            passthru($command, $returnCode);

            if ($returnCode === 0) {
                $output->writeln('');
                $output->writeln('<info>Dead letter queue processing completed successfully!</info>');
                $output->writeln('');
                $output->writeln('To check results:');
                $output->writeln('  - View logs: tail -f var/log/order_email_queue.log');
                $output->writeln('  - Check queue status: bin/magento learning:queue:status');
                $output->writeln('');

                return Cli::RETURN_SUCCESS;
            } else {
                $output->writeln('');
                $output->writeln('<error>Dead letter queue processing failed</error>');
                $output->writeln('');

                return Cli::RETURN_FAILURE;
            }
        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln('<error>Error processing dead letter queue: ' . $e->getMessage() . '</error>');
            $output->writeln('');

            return Cli::RETURN_FAILURE;
        }
    }
}
