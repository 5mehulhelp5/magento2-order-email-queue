# Magento 2 Order Email Queue Module

A comprehensive Magento 2 module demonstrating message queue functionality by queuing order confirmation emails instead of sending them synchronously during checkout.

## Overview

This module showcases all essential message queue concepts in Magento 2:
- Publisher/Consumer pattern with RabbitMQ
- Queue topology (Exchange, Binding, Queue)
- Plugin system to intercept default email sending
- Message acknowledgment and processing
- Retry mechanism with exponential backoff
- Dead Letter Queue (DLQ) for failed messages
- Multiple concurrent workers support
- Queue monitoring and management via RabbitMQ Management UI

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

# Verify consumers are registered
bin/magento queue:consumers:list | grep orderEmail
```

Expected output:
```
orderEmailConsumer
orderEmailDeadLetterConsumer
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
   - **Note**: Disable this in production to prevent intentional failures

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

### Starting the Main Consumer

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
- Instructions for checking queue depth via RabbitMQ Management UI

#### Manually Process Main Queue

```bash
# Process 10 messages (default)
bin/magento learning:queue:process

# Process specific number of messages
bin/magento learning:queue:process --max-messages=20
```

#### Process Dead Letter Queue

```bash
# Process 10 DLQ messages (default)
bin/magento learning:queue:process-dlq

# Process specific number of DLQ messages
bin/magento learning:queue:process-dlq --max-messages=5
```

### Monitoring

#### View Logs

```bash
# Follow log in real-time
tail -f var/log/order_email_queue.log

# View last 50 lines
tail -n 50 var/log/order_email_queue.log

# Search for specific order
grep "order_id\":123" var/log/order_email_queue.log
```

#### RabbitMQ Management UI

Access the RabbitMQ Management UI at: `http://localhost:15672`

**Default credentials**: guest/guest

**To check queues:**
1. Login to Management UI
2. Navigate to **Queues** tab
3. Look for queues:
   - `order.email.queue` - Main queue
   - `order.email.queue.dead` - Dead letter queue

**Queue Information Available:**
- Total messages in queue
- Messages ready for processing
- Unacknowledged messages
- Message rates (incoming/outgoing)
- Consumer connections

## Features

### 1. Plugin System

**File**: `Plugin/OrderSenderPlugin.php`

The module uses an **around plugin** to intercept Magento's default `OrderSender::send()` method:

```php
public function aroundSend(
    OrderSender $subject,
    callable $proceed,
    Order $order,
    bool $forceSyncMode = false
): bool
```

**How it works:**
- When queue is **enabled**: Prevents immediate email sending and lets observer queue the message
- When queue is **disabled**: Allows normal Magento email sending
- When `forceSyncMode=true`: Allows consumer to send email (bypasses queue check)

### 2. Event Observer

**File**: `Observer/OrderPlaceAfterObserver.php`
**Event**: `checkout_submit_all_after`

**Why this event?**
- Fires after order is completely saved to database
- Order has valid `entity_id` at this point
- Ensures reliable order data for queue message

**What it does:**
- Validates order object
- Prepares order data for queue message
- Publishes message to `order.email.send` topic

### 3. Publisher

**File**: `Model/Publisher/OrderEmailPublisher.php`
**Topic**: `order.email.send`

**Responsibilities:**
- Formats order data as JSON message
- Publishes to RabbitMQ exchange
- Logs publishing activity

### 4. Consumer

**File**: `Model/EmailProcessor.php`
**Queue**: `order.email.queue`
**Consumer Name**: `orderEmailConsumer`

**Processing Flow:**
1. Receives message from queue
2. Validates and decodes JSON data
3. Loads order by ID
4. Sends order confirmation email with `forceSyncMode=true`
5. Acknowledges message on success
6. Rejects message on failure (triggers retry)

**Retry Logic:**
- Tracks retry count in message
- Maximum 3 attempts (configurable)
- After max retries, message moves to DLQ

### 5. Dead Letter Queue (DLQ)

**File**: `Model/DeadLetterProcessor.php`
**Queue**: `order.email.queue.dead`
**Consumer Name**: `orderEmailDeadLetterConsumer`

**Purpose:**
- Processes messages that failed after max retry attempts
- Logs failed messages for manual intervention
- Prevents message loss

**Processing Options:**
- **Default**: Logs message details for manual review
- **Optional**: Uncomment `requeue()` method to automatically retry from DLQ

### 6. Custom Logger

**Files**: `Logger/Handler.php`, `Logger/Logger.php`
**Log File**: `var/log/order_email_queue.log`

**Logs all activities:**
- Message publishing
- Message processing
- Email sending success/failure
- Retry attempts
- DLQ operations

## Architecture

### Message Flow Diagram

```
Order Placed (Checkout)
         ↓
checkout_submit_all_after Event
         ↓
OrderPlaceAfterObserver
         ↓
OrderEmailPublisher
         ↓
RabbitMQ Exchange (magento)
         ↓
Binding (order.email.send)
         ↓
order.email.queue
         ↓
EmailProcessor (Consumer)
         ↓
   ┌─────┴─────┐
   ↓           ↓
Success    Failure
   ↓           ↓
Email Sent  Retry (increment count)
            ↓
        Retry < 3?
         ↓     ↓
        Yes    No
         ↓     ↓
    Requeue   Dead Letter Queue
              (order.email.queue.dead)
                    ↓
            DeadLetterProcessor
                    ↓
            Manual Intervention
```

### Key Components

#### 1. Plugin: OrderSenderPlugin
- **Purpose**: Intercepts default Magento email sending
- **Location**: `Plugin/OrderSenderPlugin.php`
- **Type**: Around plugin on `Magento\Sales\Model\Order\Email\Sender\OrderSender::send()`

#### 2. Observer: OrderPlaceAfterObserver
- **Event**: `checkout_submit_all_after`
- **Location**: `Observer/OrderPlaceAfterObserver.php`
- **Purpose**: Captures order placement and queues email

#### 3. Publisher: OrderEmailPublisher
- **Topic**: `order.email.send`
- **Location**: `Model/Publisher/OrderEmailPublisher.php`
- **Purpose**: Publishes order data to message queue

#### 4. Consumer: EmailProcessor
- **Queue**: `order.email.queue`
- **Location**: `Model/EmailProcessor.php`
- **Handler**: `Learning\OrderEmailQueue\Model\EmailProcessor::process`
- **Purpose**: Processes messages and sends emails

#### 5. DLQ Consumer: DeadLetterProcessor
- **Queue**: `order.email.queue.dead`
- **Location**: `Model/DeadLetterProcessor.php`
- **Handler**: `Learning\OrderEmailQueue\Model\DeadLetterProcessor::process`
- **Purpose**: Handles failed messages after max retries

#### 6. CLI Commands
- **QueueStatusCommand**: Check queue configuration and status
- **ProcessQueueCommand**: Manually process main queue
- **ProcessDlqCommand**: Manually process dead letter queue

## Message Format

Messages in the queue follow this JSON structure:

```json
{
    "order_id": 308,
    "increment_id": "000000492",
    "customer_email": "customer@example.com",
    "customer_name": "John Doe",
    "created_at": "2025-10-17 07:34:26",
    "retry_count": 0
}
```

**Fields:**
- `order_id`: Internal order entity ID
- `increment_id`: Customer-visible order number
- `customer_email`: Email address for order confirmation
- `customer_name`: Customer full name
- `created_at`: Order creation timestamp
- `retry_count`: Number of processing attempts (0-3)

## Queue Configuration

### Topology

**Exchange**: `magento` (type: topic)
**Main Queue**: `order.email.queue`
**Dead Letter Queue**: `order.email.queue.dead`
**Topic**: `order.email.send`
**DLQ Topic**: `order.email.send.dead`

### Consumer Settings

**Main Consumer** (`orderEmailConsumer`):
- Connection: amqp (RabbitMQ)
- Max Messages: 100 (configurable)
- Handler: `EmailProcessor::process`

**DLQ Consumer** (`orderEmailDeadLetterConsumer`):
- Connection: amqp (RabbitMQ)
- Max Messages: 10
- Handler: `DeadLetterProcessor::process`

## Testing

### Test 1: Basic Flow

**Objective**: Verify message is queued and processed successfully

```bash
# 1. Enable the module in admin
# Stores > Configuration > Learning Modules > Order Email Queue
# Set "Enable Queue" to "Yes"
# Save Configuration

# 2. Clear cache
bin/magento cache:flush

# 3. Place an order through Magento frontend or admin

# 4. Check RabbitMQ Management UI
# Navigate to http://localhost:15672 > Queues tab
# Look for "order.email.queue" with 1 message

# 5. Process the queue
bin/magento queue:consumers:start orderEmailConsumer --max-messages=1

# 6. Verify in logs
tail var/log/order_email_queue.log
```

**Expected Result**:
```
[2025-10-17T07:34:26] OrderEmailQueueLogger.INFO: Message published successfully
[2025-10-17T07:34:39] OrderEmailQueueLogger.INFO: Processing order email message
[2025-10-17T07:34:43] OrderEmailQueueLogger.INFO: Order confirmation email sent successfully
```

### Test 2: Retry Logic

**Objective**: Test automatic retry on failure

```bash
# 1. Enable "Simulate Random Failures" in admin configuration
# Stores > Configuration > Learning Modules > Order Email Queue
# Set "Simulate Random Failures" to "Yes"

# 2. Place multiple orders (5-10)

# 3. Process the queue
bin/magento queue:consumers:start orderEmailConsumer --max-messages=10

# 4. Monitor logs for retry attempts
tail -f var/log/order_email_queue.log
```

**Expected Result**: See warning messages with retry counts
```
[2025-10-17T10:15:30] OrderEmailQueueLogger.WARNING: Simulated failure - retry attempt 1/3
[2025-10-17T10:15:35] OrderEmailQueueLogger.WARNING: Simulated failure - retry attempt 2/3
[2025-10-17T10:15:40] OrderEmailQueueLogger.WARNING: Simulated failure - retry attempt 3/3
```

### Test 3: Dead Letter Queue

**Objective**: Verify messages move to DLQ after max retries

```bash
# 1. Temporarily disable email sending or enable simulate failures

# 2. Place an order

# 3. Process the queue multiple times until message moves to DLQ
bin/magento queue:consumers:start orderEmailConsumer --max-messages=1
# Repeat 3 times

# 4. Check DLQ via RabbitMQ Management UI
# Navigate to http://localhost:15672 > Queues
# Look for "order.email.queue.dead" with 1 message

# 5. Process DLQ
bin/magento learning:queue:process-dlq --max-messages=1

# 6. Check logs
grep "dead letter queue" var/log/order_email_queue.log
```

**Expected Result**:
```
[2025-10-17T07:32:34] OrderEmailQueueLogger.INFO: Processing message from dead letter queue
[2025-10-17T07:32:34] OrderEmailQueueLogger.WARNING: Message found in dead letter queue - manual intervention required
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
```

**Expected Result**: Both consumers process messages concurrently without conflicts

### Test 5: Queue Status Commands

```bash
# Check queue status
bin/magento learning:queue:status

# Manually process queue
bin/magento learning:queue:process --max-messages=5

# Process dead letter queue
bin/magento learning:queue:process-dlq --max-messages=2
```

**Expected Result**: Commands execute without errors and display information

## Troubleshooting

### Issue: Consumer not processing messages

**Check 1: Is consumer running?**
```bash
ps aux | grep orderEmailConsumer
```

**Check 2: Is RabbitMQ running?**
```bash
# Check RabbitMQ service status
systemctl status rabbitmq-server

# Or check via Management UI
# Navigate to http://localhost:15672
```

**Check 3: Verify queue exists**
- Open RabbitMQ Management UI: `http://localhost:15672`
- Navigate to **Queues** tab
- Look for `order.email.queue`

**Check 4: Check logs for errors**
```bash
tail -n 100 var/log/order_email_queue.log
tail -n 100 var/log/system.log | grep queue
```

### Issue: Messages stuck in queue

**Solution 1: Manually process messages**
```bash
bin/magento learning:queue:process --max-messages=10
```

**Solution 2: Check for consumer errors**
```bash
tail -n 100 var/log/system.log | grep orderEmailConsumer
```

**Solution 3: Restart consumer**
```bash
# Kill existing consumer
pkill -f orderEmailConsumer

# Start new consumer
bin/magento queue:consumers:start orderEmailConsumer
```

### Issue: Module not enabled after installation

**Solution:**
```bash
# Clear cache and regenerate
bin/magento cache:clean
bin/magento cache:flush
bin/magento setup:upgrade
bin/magento setup:di:compile
```

### Issue: Emails sent immediately instead of being queued

**Check 1: Is queue enabled in configuration?**
- Navigate to: Stores > Configuration > Learning Modules > Order Email Queue
- Verify "Enable Queue" is set to "Yes"

**Check 2: Clear configuration cache**
```bash
bin/magento cache:clean config
```

**Check 3: Check logs**
```bash
tail var/log/order_email_queue.log | grep "prevented"
```

Expected to see:
```
OrderEmailQueueLogger.INFO: Order email sending prevented - will be queued instead
```

### Issue: Testing failures enabled in production

**Symptom**: Emails failing with "Simulated failure for testing purposes"

**Solution:**
```bash
# Disable simulate failures
bin/magento config:set learning_order_email_queue/testing/simulate_failures 0

# Clear config cache
bin/magento cache:clean config
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

**Note**: Cron approach may lead to overlapping processes. Supervisor is recommended for production.

## Performance Considerations

1. **Consumer Instances**: Run 2-3 consumer instances for better throughput
2. **Batch Size**: Adjust `maxMessages` in `queue_consumer.xml` based on server capacity (default: 100)
3. **Max Retries**: Consider business requirements when setting retry attempts (default: 3)
4. **Monitoring**: Set up monitoring alerts for DLQ messages accumulation
5. **Log Rotation**: Configure log rotation for `var/log/order_email_queue.log`

## Security

- Module follows Magento 2 security best practices
- ACL configuration for admin access control
- Proper input validation and error handling
- No sensitive data logged (passwords, credit cards, etc.)
- Uses Magento's built-in email sender for secure SMTP handling

## License

Open Software License (OSL 3.0)

## Support

For issues, questions, or contributions:
- Review logs: `var/log/order_email_queue.log`
- Check Magento system logs: `var/log/system.log`
- Verify RabbitMQ status via Management UI: `http://localhost:15672`

## Author

Learning Team

## Additional Documentation

For more detailed information, see the `docs/` directory:
- Flow diagrams and architecture details
- Queue configuration guide
- RabbitMQ setup and troubleshooting
