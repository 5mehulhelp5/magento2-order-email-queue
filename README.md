# Magento 2 Order Email Queue Module

A comprehensive Magento 2 module demonstrating message queue functionality by queuing order confirmation emails instead of sending them synchronously during checkout.

## Overview

This module showcases all essential message queue concepts:
- Publisher/Consumer pattern
- Queue topology (Exchange, Binding, Queue)
- Message acknowledgment
- Retry mechanism with exponential backoff
- Dead Letter Queue (DLQ)
- Multiple concurrent workers
- Queue monitoring and management

## Module Information

- **Vendor**: Learning
- **Module Name**: OrderEmailQueue
- **Namespace**: `Learning\OrderEmailQueue`
- **Version**: 1.0.0

## Requirements

- Magento 2.4.x or higher
- PHP 7.4+ or 8.1+
- RabbitMQ (recommended) or MySQL queue backend
- Composer

## Installation

### Step 1: Copy Module Files

Place the module in your Magento installation:
```bash
# Module should be located at:
app/code/Learning/OrderEmailQueue/
```

### Step 2: Enable Module

```bash
# Enable the module
bin/magento module:enable Learning_OrderEmailQueue

# Run setup upgrade
bin/magento setup:upgrade

# Compile dependency injection
bin/magento setup:di:compile

# Deploy static content (if needed)
bin/magento setup:static-content:deploy -f

# Clear cache
bin/magento cache:flush
```

### Step 3: Verify Installation

```bash
# Check if module is enabled
bin/magento module:status Learning_OrderEmailQueue

# Verify consumer is registered
bin/magento queue:consumers:list | grep orderEmailConsumer
```

## Configuration

### Admin Configuration

Navigate to: **Stores > Configuration > Learning Modules > Order Email Queue**

#### General Settings

1. **Enable Queue**: Enable/disable asynchronous order email processing
   - Yes: Order emails will be queued and processed asynchronously
   - No: Order emails will be sent immediately (default Magento behavior)

2. **Max Retry Attempts**: Number of retry attempts before moving message to DLQ (default: 3)

3. **Batch Size**: Number of messages to process per consumer run (default: 100)

#### Testing Settings

4. **Simulate Random Failures**: Enable 20% failure rate for testing retry and DLQ functionality

### RabbitMQ Configuration

Ensure RabbitMQ is configured in `app/etc/env.php`:

```php
'queue' => [
    'amqp' => [
        'host' => 'localhost',
        'port' => '5672',
        'user' => 'guest',
        'password' => 'guest',
        'virtualhost' => '/'
    ]
]
```

## Usage

### Starting the Consumer

#### Continuous Processing (Recommended for Production)

```bash
# Run consumer continuously
bin/magento queue:consumers:start orderEmailConsumer
```

#### Process Specific Number of Messages

```bash
# Process 50 messages and exit
bin/magento queue:consumers:start orderEmailConsumer --max-messages=50
```

#### Running as Background Process

```bash
# Run consumer in background
nohup bin/magento queue:consumers:start orderEmailConsumer > /dev/null 2>&1 &
```

### Custom CLI Commands

#### Check Queue Status

```bash
bin/magento learning:queue:status
```

This displays:
- Consumer configuration
- Queue information
- Instructions for checking queue depth

#### Manually Process Queue

```bash
# Process 10 messages (default)
bin/magento learning:queue:process

# Process specific number of messages
bin/magento learning:queue:process --max-messages=20
```

### Monitoring

#### View Logs

```bash
# Follow log in real-time
tail -f var/log/order_email_queue.log

# View last 50 lines
tail -n 50 var/log/order_email_queue.log
```

#### Check RabbitMQ Queues

```bash
# List all queues with message counts
rabbitmqctl list_queues name messages consumers

# Check specific queue
rabbitmqctl list_queues | grep order.email
```

#### RabbitMQ Management UI

Access at: `http://localhost:15672`
- Default credentials: guest/guest
- Navigate to "Queues" tab to see message statistics

## Testing

### Test 1: Basic Flow

**Objective**: Verify message is queued and processed successfully

```bash
# 1. Enable the module in admin
# Stores > Configuration > Learning Modules > Order Email Queue
# Set "Enable Queue" to "Yes"

# 2. Place an order through Magento frontend or admin

# 3. Check if message was queued
rabbitmqctl list_queues | grep order.email.queue

# 4. Process the queue
bin/magento queue:consumers:start orderEmailConsumer --max-messages=1

# 5. Verify in logs
tail var/log/order_email_queue.log

# Expected: Email sent successfully
```

### Test 2: Retry Logic

**Objective**: Test automatic retry on failure

```bash
# 1. Enable "Simulate Random Failures" in admin configuration

# 2. Place multiple orders (5-10)

# 3. Process the queue
bin/magento queue:consumers:start orderEmailConsumer --max-messages=10

# 4. Monitor logs for retry attempts
tail -f var/log/order_email_queue.log

# Expected: See warning messages with retry counts (1/3, 2/3, 3/3)
```

### Test 3: Dead Letter Queue

**Objective**: Verify messages move to DLQ after max retries

```bash
# 1. Temporarily disable email sending (disconnect SMTP or set invalid config)

# 2. Place an order

# 3. Process the queue multiple times
bin/magento queue:consumers:start orderEmailConsumer --max-messages=1

# 4. Repeat step 3 at least 3 times

# 5. Check DLQ
rabbitmqctl list_queues | grep dead

# 6. Check logs
grep "dead letter queue" var/log/order_email_queue.log

# Expected: Message moved to DLQ after 3 failed attempts
```

### Test 4: Concurrent Workers

**Objective**: Test multiple consumers processing simultaneously

```bash
# 1. Queue multiple orders (10+)

# 2. Start multiple consumers in different terminals
# Terminal 1:
bin/magento queue:consumers:start orderEmailConsumer --max-messages=5

# Terminal 2:
bin/magento queue:consumers:start orderEmailConsumer --max-messages=5

# 3. Monitor processing
tail -f var/log/order_email_queue.log

# Expected: Both consumers process messages concurrently
```

### Test 5: Queue Status Commands

```bash
# Check queue status
bin/magento learning:queue:status

# Manually process queue
bin/magento learning:queue:process --max-messages=5

# Expected: Commands execute without errors and display information
```

## Architecture

### Message Flow

```
Order Placed
    ↓
Observer (OrderPlaceAfterObserver)
    ↓
Publisher (OrderEmailPublisher)
    ↓
RabbitMQ Exchange (magento)
    ↓
Queue (order.email.queue)
    ↓
Consumer (EmailProcessor)
    ↓
    ├─→ Success: Email Sent
    └─→ Failure: Retry (up to 3x)
            ↓
        Dead Letter Queue (order.email.queue.dead)
```

### Key Components

#### 1. Observer
- **File**: `Observer/OrderPlaceAfterObserver.php`
- **Event**: `sales_order_place_after`
- **Purpose**: Captures order placement and publishes to queue

#### 2. Publisher
- **File**: `Model/Publisher/OrderEmailPublisher.php`
- **Topic**: `order.email.send`
- **Purpose**: Publishes order data to message queue

#### 3. Consumer
- **File**: `Model/EmailProcessor.php`
- **Queue**: `order.email.queue`
- **Purpose**: Processes messages and sends emails

#### 4. Logger
- **Files**: `Logger/Handler.php`, `Logger/Logger.php`
- **Log File**: `var/log/order_email_queue.log`
- **Purpose**: Custom logging for all queue operations

#### 5. CLI Commands
- **Files**: `Console/Command/QueueStatusCommand.php`, `Console/Command/ProcessQueueCommand.php`
- **Purpose**: Manual queue management and monitoring

## Message Format

```json
{
    "order_id": 123,
    "increment_id": "000000001",
    "customer_email": "customer@example.com",
    "customer_name": "John Doe",
    "created_at": "2025-10-16 10:30:00",
    "retry_count": 0
}
```

## Queue Configuration

### Topology
- **Exchange**: magento (topic)
- **Main Queue**: order.email.queue
- **Dead Letter Queue**: order.email.queue.dead
- **Topic**: order.email.send
- **DLQ Topic**: order.email.send.dead

### Consumer Settings
- **Consumer Name**: orderEmailConsumer
- **Connection**: amqp (RabbitMQ)
- **Max Messages**: 100 (configurable)
- **Handler**: EmailProcessor::process

## Troubleshooting

### Issue: Consumer not processing messages

**Solution**:
```bash
# Check if consumer is running
ps aux | grep orderEmailConsumer

# Check RabbitMQ connection
rabbitmqctl status

# Verify queue exists
rabbitmqctl list_queues | grep order.email

# Check logs for errors
tail var/log/order_email_queue.log
```

### Issue: Messages stuck in queue

**Solution**:
```bash
# Manually process messages
bin/magento learning:queue:process --max-messages=10

# Check for consumer errors
tail var/log/system.log | grep queue

# Restart consumer
pkill -f orderEmailConsumer
bin/magento queue:consumers:start orderEmailConsumer
```

### Issue: Module not enabled after installation

**Solution**:
```bash
# Clear cache and regenerate
bin/magento cache:clean
bin/magento cache:flush
bin/magento setup:upgrade
bin/magento setup:di:compile
```

## Production Deployment

### Using Supervisor (Recommended)

Create supervisor configuration: `/etc/supervisor/conf.d/magento-queue-orderEmailConsumer.conf`

```ini
[program:magento-queue-orderEmailConsumer]
command=/usr/bin/php /var/www/html/magento/bin/magento queue:consumers:start orderEmailConsumer
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/orderEmailConsumer.log
```

Reload supervisor:
```bash
supervisorctl reread
supervisorctl update
supervisorctl start magento-queue-orderEmailConsumer:*
```

### Using Cron

Add to crontab:
```bash
* * * * * /usr/bin/php /var/www/html/magento/bin/magento queue:consumers:start orderEmailConsumer --max-messages=100 >> /var/log/magento-queue.log 2>&1
```

## Performance Considerations

1. **Consumer Instances**: Run 2-3 consumer instances for better throughput
2. **Batch Size**: Adjust based on server capacity (default: 100)
3. **Max Retries**: Consider business requirements (default: 3)
4. **Monitoring**: Set up monitoring alerts for DLQ messages
5. **Log Rotation**: Configure log rotation for `var/log/order_email_queue.log`

## Security

- Module follows Magento 2 security best practices
- ACL configuration for admin access
- Proper input validation and error handling
- No sensitive data in logs

## License

Open Software License (OSL 3.0)

## Support

For issues, questions, or contributions:
- Review logs: `var/log/order_email_queue.log`
- Check Magento system logs: `var/log/system.log`
- Verify RabbitMQ status and configuration

## Author

Learning Team

## Version History

- **1.0.0** (2025-10-16): Initial release
  - Complete message queue implementation
  - Retry mechanism with exponential backoff
  - Dead Letter Queue support
  - Custom CLI commands
  - Admin configuration
  - Custom logging
