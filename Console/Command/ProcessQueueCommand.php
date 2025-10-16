<?php
/**
 * Process Queue CLI Command
 * Manually triggers queue processing
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
use Symfony\Component\Process\Process;

/**
 * Class ProcessQueueCommand
 * CLI command to manually process queue messages
 */
class ProcessQueueCommand extends Command
{
    /**
     * Consumer name
     */
    private const CONSUMER_NAME = 'orderEmailConsumer';

    /**
     * Default max messages
     */
    private const DEFAULT_MAX_MESSAGES = 10;

    /**
     * Configure command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('learning:queue:process')
            ->setDescription('Manually process order email queue messages')
            ->addOption(
                'max-messages',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of messages to process',
                self::DEFAULT_MAX_MESSAGES
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

        if ($maxMessages <= 0) {
            $output->writeln('<error>Max messages must be greater than 0</error>');
            return Cli::RETURN_FAILURE;
        }

        $output->writeln('<info>Starting to process order email queue...</info>');
        $output->writeln(sprintf('Consumer: %s', self::CONSUMER_NAME));
        $output->writeln(sprintf('Max messages: %d', $maxMessages));
        $output->writeln('');

        try {
            // Get Magento bin directory
            $magentoRoot = BP;
            $binMagento = $magentoRoot . '/bin/magento';

            if (!file_exists($binMagento)) {
                $output->writeln('<error>Magento bin/magento not found</error>');
                return Cli::RETURN_FAILURE;
            }

            $output->writeln('<comment>Executing consumer...</comment>');
            $output->writeln('');

            // Build command
            $command = [
                'php',
                $binMagento,
                'queue:consumers:start',
                self::CONSUMER_NAME,
                '--max-messages=' . $maxMessages
            ];

            // Create and run process
            $process = new Process($command);
            $process->setTimeout(300); // 5 minutes timeout

            $process->run(function ($type, $buffer) use ($output) {
                $output->write($buffer);
            });

            $output->writeln('');

            if ($process->isSuccessful()) {
                $output->writeln('<info>Queue processing completed successfully!</info>');
                $output->writeln('');
                $output->writeln('<comment>To check results:</comment>');
                $output->writeln('  - View logs: tail -f var/log/order_email_queue.log');
                $output->writeln('  - Check queue status: bin/magento learning:queue:status');
                return Cli::RETURN_SUCCESS;
            } else {
                $output->writeln('<error>Queue processing failed</error>');
                $output->writeln($process->getErrorOutput());
                return Cli::RETURN_FAILURE;
            }
        } catch (\Exception $e) {
            $output->writeln('<error>Error processing queue: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }
}
