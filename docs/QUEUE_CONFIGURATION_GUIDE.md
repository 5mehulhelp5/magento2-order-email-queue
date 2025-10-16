# Magento 2 Queue Configuration Files - Complete Guide

This document explains all queue-related XML configuration files, their purposes, relationships, and the sequence to create them.

---

## Table of Contents

1. [Overview of Queue Configuration Files](#overview-of-queue-configuration-files)
2. [File Creation Sequence](#file-creation-sequence)
3. [Detailed File Explanations](#detailed-file-explanations)
4. [File Relationships and Dependencies](#file-relationships-and-dependencies)
5. [Complete Example with Flow](#complete-example-with-flow)
6. [Best Practices](#best-practices)

---

## Overview of Queue Configuration Files

When implementing message queue functionality in Magento 2, you need **5 XML configuration files** (4 required + 1 optional):

| File | Location | Required? | Purpose |
|------|----------|-----------|---------|
| `communication.xml` | `etc/` | **Required** | Defines topics and their data contracts |
| `queue_topology.xml` | `etc/` | **Required** | Defines queue structure (exchanges, queues, bindings) |
| `queue_publisher.xml` | `etc/` | **Required** | Configures message publishers |
| `queue_consumer.xml` | `etc/` | **Required** | Configures message consumers |
| `queue.xml` | `etc/` | **Optional** | Maps consumers to cron jobs for auto-start |

---

## File Creation Sequence

### Recommended Order:

```
1. communication.xml       (Define WHAT you want to communicate)
   ↓
2. queue_topology.xml      (Define WHERE messages go - queue structure)
   ↓
3. queue_publisher.xml     (Define HOW to send messages)
   ↓
4. queue_consumer.xml      (Define HOW to receive/process messages)
   ↓
5. queue.xml               (OPTIONAL - Define auto-start consumers via cron)
```

### Why This Order?

1. **Start with communication.xml**: Define your topics and data contracts first
2. **Then queue_topology.xml**: Set up the queue infrastructure
3. **Then queue_publisher.xml**: Configure how to publish to those queues
4. **Then queue_consumer.xml**: Configure how to consume from those queues
5. **Finally queue.xml** (optional): Set up automatic consumer management

---

## Detailed File Explanations

---

## 1. communication.xml

### Purpose
Defines the **communication contract** between publisher and consumer:
- Topic names
- Data types (request/response)
- Handler methods
- Service contracts

### Location
`app/code/Vendor/Module/etc/communication.xml`

### Structure
```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Communication/etc/communication.xsd">

    <!-- Define a topic -->
    <topic name="topic.name.here" request="DataType">
        <handler name="handlerName"
                 type="Vendor\Module\Model\Handler"
                 method="methodName"/>
    </topic>
</config>
```

### Key Elements

#### `<topic>` - Defines a communication topic
- **name**: Unique topic identifier (e.g., `order.email.send`)
- **request**: Data type for the message
  - Can be: `string`, `int`, `mixed`, or a service contract interface
- **response**: (Optional) Data type for response (for RPC pattern)

#### `<handler>` - Defines who processes the topic
- **name**: Unique handler identifier
- **type**: Fully qualified class name of the handler
- **method**: Method name to call on the handler class

### Example from Our Module

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Communication/etc/communication.xsd">

    <!-- Main Topic -->
    <topic name="order.email.send" request="string">
        <handler name="orderEmailHandler"
                 type="Learning\OrderEmailQueue\Model\EmailProcessor"
                 method="process"/>
    </topic>

    <!-- Dead Letter Topic -->
    <topic name="order.email.send.dead" request="string"/>
</config>
```

### What This Means

1. **Topic: order.email.send**
   - Accepts string data (JSON in our case)
   - Handled by `EmailProcessor::process()` method
   - Used for normal message processing

2. **Topic: order.email.send.dead**
   - Accepts string data
   - No handler (used only for storage in DLQ)
   - Used for failed messages

### Data Type Options

```xml
<!-- String data -->
<topic name="my.topic" request="string"/>

<!-- Integer data -->
<topic name="my.topic" request="int"/>

<!-- Mixed/Array data -->
<topic name="my.topic" request="mixed"/>

<!-- Service Contract (strongly typed) -->
<topic name="my.topic" request="Vendor\Module\Api\Data\OrderDataInterface"/>
```

### Multiple Handlers (Optional)

You can have multiple handlers for one topic:

```xml
<topic name="order.email.send" request="string">
    <handler name="emailHandler"
             type="Vendor\Module\Model\EmailProcessor"
             method="process"/>
    <handler name="notificationHandler"
             type="Vendor\Module\Model\NotificationProcessor"
             method="sendNotification"/>
</topic>
```

---

## 2. queue_topology.xml

### Purpose
Defines the **queue infrastructure** in RabbitMQ:
- Exchanges (message routers)
- Queues (message storage)
- Bindings (connections between exchanges and queues)
- Dead Letter Queue configuration

### Location
`app/code/Vendor/Module/etc/queue_topology.xml`

### Structure
```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/topology.xsd">

    <exchange name="exchangeName" type="topic" connection="amqp">
        <binding id="bindingId"
                 topic="topic.name"
                 destinationType="queue"
                 destination="queueName"/>
    </exchange>
</config>
```

### Key Concepts

#### **Exchange**
- Message router in RabbitMQ
- Receives messages and routes them to queues
- Types: `topic`, `direct`, `fanout`, `headers`
- Magento uses: **topic** type

#### **Queue**
- Message storage
- Holds messages until consumers process them
- Defined by the `destination` attribute

#### **Binding**
- Connection between exchange and queue
- Uses topic name as routing key
- Determines which messages go to which queue

### Key Elements

#### `<exchange>` - Defines the message exchange
- **name**: Exchange name (Magento uses `magento` by default)
- **type**: Exchange type (use `topic`)
- **connection**: Connection name (use `amqp` for RabbitMQ)

#### `<binding>` - Connects topic to queue
- **id**: Unique binding identifier
- **topic**: Topic name (must match `communication.xml`)
- **destinationType**: Usually `queue`
- **destination**: Queue name

#### `<arguments>` - Queue configuration
- Used for DLQ setup, TTL, priority, etc.

### Example from Our Module

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/topology.xsd">

    <exchange name="magento" type="topic" connection="amqp">

        <!-- Main Queue Binding -->
        <binding id="orderEmailBinding"
                 topic="order.email.send"
                 destinationType="queue"
                 destination="order.email.queue">
            <arguments>
                <!-- Dead Letter Exchange -->
                <argument name="x-dead-letter-exchange" xsi:type="string">magento</argument>
                <!-- Dead Letter Routing Key -->
                <argument name="x-dead-letter-routing-key" xsi:type="string">order.email.send.dead</argument>
            </arguments>
        </binding>

        <!-- Dead Letter Queue Binding -->
        <binding id="orderEmailDeadLetterBinding"
                 topic="order.email.send.dead"
                 destinationType="queue"
                 destination="order.email.queue.dead"/>
    </exchange>
</config>
```

### What This Creates in RabbitMQ

```
Exchange: magento (topic)
    ↓
    ├─→ Binding: orderEmailBinding
    │   ├─ Topic: order.email.send
    │   └─→ Queue: order.email.queue (with DLQ config)
    │
    └─→ Binding: orderEmailDeadLetterBinding
        ├─ Topic: order.email.send.dead
        └─→ Queue: order.email.queue.dead
```

### Flow with Dead Letter Queue

```
1. Message published to: order.email.send
   ↓
2. Routed to: order.email.queue
   ↓
3. Consumer processes message
   ↓
4a. SUCCESS → Message removed
   ↓
4b. FAILURE (after retries) → Message sent to DLQ
   ↓
5. Dead letter routing key: order.email.send.dead
   ↓
6. Routed to: order.email.queue.dead
```

### Common Arguments

```xml
<arguments>
    <!-- Dead Letter Exchange -->
    <argument name="x-dead-letter-exchange" xsi:type="string">magento</argument>

    <!-- Dead Letter Routing Key -->
    <argument name="x-dead-letter-routing-key" xsi:type="string">my.topic.dead</argument>

    <!-- Message TTL (milliseconds) -->
    <argument name="x-message-ttl" xsi:type="number">3600000</argument>

    <!-- Queue Length Limit -->
    <argument name="x-max-length" xsi:type="number">10000</argument>

    <!-- Queue Priority -->
    <argument name="x-max-priority" xsi:type="number">10</argument>
</arguments>
```

---

## 3. queue_publisher.xml

### Purpose
Configures **how messages are published**:
- Which connection to use (RabbitMQ, MySQL, etc.)
- Which exchange to publish to
- Topic-to-connection mapping

### Location
`app/code/Vendor/Module/etc/queue_publisher.xml`

### Structure
```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/publisher.xsd">

    <publisher topic="topic.name" connection="amqp">
        <connection name="amqp" exchange="exchangeName"/>
    </publisher>
</config>
```

### Key Elements

#### `<publisher>` - Configures message publishing
- **topic**: Topic name (must match `communication.xml`)
- **connection**: Connection type to use

#### `<connection>` - Specifies connection details
- **name**: Connection name (e.g., `amqp`, `db`)
- **exchange**: Exchange name (must match `queue_topology.xml`)

### Example from Our Module

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/publisher.xsd">

    <!-- Main Topic Publisher -->
    <publisher topic="order.email.send" connection="amqp">
        <connection name="amqp" exchange="magento"/>
    </publisher>

    <!-- Dead Letter Topic Publisher -->
    <publisher topic="order.email.send.dead" connection="amqp">
        <connection name="amqp" exchange="magento"/>
    </publisher>
</config>
```

### What This Means

1. **Publisher for order.email.send**
   - Uses AMQP connection (RabbitMQ)
   - Publishes to `magento` exchange
   - Messages route based on topic name

2. **Publisher for order.email.send.dead**
   - Also uses AMQP
   - Publishes failed messages to DLQ

### Connection Types

```xml
<!-- RabbitMQ -->
<publisher topic="my.topic" connection="amqp">
    <connection name="amqp" exchange="magento"/>
</publisher>

<!-- MySQL (fallback) -->
<publisher topic="my.topic" connection="db">
    <connection name="db" exchange="magento-db"/>
</publisher>
```

### Multiple Connections (High Availability)

```xml
<publisher topic="my.topic">
    <!-- Primary: RabbitMQ -->
    <connection name="amqp" exchange="magento"/>
    <!-- Fallback: MySQL -->
    <connection name="db" exchange="magento-db"/>
</publisher>
```

---

## 4. queue_consumer.xml

### Purpose
Configures **how messages are consumed**:
- Consumer name
- Which queue to read from
- Handler to process messages
- Processing limits

### Location
`app/code/Vendor/Module/etc/queue_consumer.xml`

### Structure
```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/consumer.xsd">

    <consumer name="consumerName"
              queue="queueName"
              connection="amqp"
              maxMessages="100"
              consumerInstance="Magento\Framework\MessageQueue\Consumer"
              handler="Vendor\Module\Model\Handler::method"/>
</config>
```

### Key Elements

#### `<consumer>` - Defines a consumer
- **name**: Unique consumer name (used in CLI)
- **queue**: Queue name (must match `queue_topology.xml`)
- **connection**: Connection type (`amqp`, `db`)
- **maxMessages**: Max messages to process per run
- **consumerInstance**: Consumer class to use
- **handler**: Handler class and method

### Example from Our Module

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/consumer.xsd">

    <consumer name="orderEmailConsumer"
              queue="order.email.queue"
              connection="amqp"
              maxMessages="100"
              consumerInstance="Magento\Framework\MessageQueue\Consumer"
              handler="Learning\OrderEmailQueue\Model\EmailProcessor::process"/>
</config>
```

### What This Means

- **Consumer Name**: `orderEmailConsumer`
  - Used in CLI: `bin/magento queue:consumers:start orderEmailConsumer`
- **Queue**: `order.email.queue`
  - Reads messages from this queue
- **Connection**: AMQP (RabbitMQ)
- **Max Messages**: 100
  - Process up to 100 messages then exit
- **Handler**: `EmailProcessor::process()`
  - Method called for each message

### Consumer Instance Types

```xml
<!-- Standard Consumer -->
<consumer consumerInstance="Magento\Framework\MessageQueue\Consumer"/>

<!-- Batch Consumer (processes multiple messages at once) -->
<consumer consumerInstance="Magento\Framework\MessageQueue\BatchConsumer"/>
```

### maxMessages Behavior

```xml
<!-- Process 100 messages then exit -->
<consumer maxMessages="100"/>

<!-- Process indefinitely (0 or omit) -->
<consumer maxMessages="0"/>
```

---

## 5. queue.xml (Optional)

### Purpose
Manages **automatic consumer startup and monitoring**:
- Maps consumers to cron jobs
- Enables automatic consumer restart if they crash
- Controls how many consumer instances to run
- Alternative to manually starting consumers or using supervisor

### Location
`app/code/Vendor/Module/etc/queue.xml`

### Is It Required?
**No, queue.xml is OPTIONAL**. You can choose between:

1. **Manual Consumer Management**: Start consumers manually
   ```bash
   bin/magento queue:consumers:start myConsumer
   ```

2. **Supervisor/Systemd**: Use external process managers (recommended for production)

3. **queue.xml**: Let Magento's cron manage consumers automatically

### When to Use queue.xml

✅ **Use queue.xml when**:
- You want automatic consumer management
- Development/testing environments
- Small to medium traffic applications
- You don't have access to supervisor/systemd

❌ **Don't use queue.xml when**:
- High-traffic production environments
- You're already using supervisor or systemd
- You need fine-grained control over consumer processes
- Multiple server/container deployments

### Structure
```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/queue.xsd">

    <broker topic="topic.name" exchange="exchangeName" type="amqp">
        <queue name="queueName" consumer="consumerName" consumerInstance="Magento\Framework\MessageQueue\Consumer" handler="Vendor\Module\Model\Handler::method" maxMessages="100"/>
    </broker>
</config>
```

### Key Elements

#### `<broker>` - Defines message broker configuration
- **topic**: Topic name (must match `communication.xml`)
- **exchange**: Exchange name (must match `queue_topology.xml`)
- **type**: Connection type (`amqp`, `db`)

#### `<queue>` - Queue and consumer mapping
- **name**: Queue name (must match `queue_topology.xml`)
- **consumer**: Consumer name (must match `queue_consumer.xml`)
- **consumerInstance**: Consumer class
- **handler**: Handler class and method
- **maxMessages**: Messages to process per run

### Example - Basic Configuration

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/queue.xsd">

    <!-- Order Email Queue -->
    <broker topic="order.email.send" exchange="magento" type="amqp">
        <queue name="order.email.queue"
               consumer="orderEmailConsumer"
               consumerInstance="Magento\Framework\MessageQueue\Consumer"
               handler="Learning\OrderEmailQueue\Model\EmailProcessor::process"
               maxMessages="100"/>
    </broker>
</config>
```

### Example - Multiple Consumers

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/queue.xsd">

    <!-- Order Email Queue -->
    <broker topic="order.email.send" exchange="magento" type="amqp">
        <queue name="order.email.queue"
               consumer="orderEmailConsumer"
               consumerInstance="Magento\Framework\MessageQueue\Consumer"
               handler="Learning\OrderEmailQueue\Model\EmailProcessor::process"
               maxMessages="100"/>
    </broker>

    <!-- Customer Notification Queue -->
    <broker topic="customer.notification.send" exchange="magento" type="amqp">
        <queue name="customer.notification.queue"
               consumer="customerNotificationConsumer"
               consumerInstance="Magento\Framework\MessageQueue\Consumer"
               handler="Vendor\Module\Model\NotificationProcessor::process"
               maxMessages="50"/>
    </broker>
</config>
```

### How queue.xml Works

1. **Cron Job Integration**:
   - Magento's `consumers_runner` cron job (runs every minute)
   - Checks if consumers defined in `queue.xml` are running
   - Automatically starts/restarts them if needed

2. **Process Management**:
   - Monitors consumer health
   - Restarts crashed consumers
   - Manages multiple consumer instances

3. **Configuration**:
   ```bash
   # Enable consumer auto-start via cron
   bin/magento queue:consumers:start --help

   # View running consumers
   ps aux | grep queue:consumers:start
   ```

### Configuration in env.php

When using `queue.xml`, you can control behavior in `app/etc/env.php`:

```php
'cron_consumers_runner' => [
    'cron_run' => true,              // Enable/disable auto-start
    'max_messages' => 10000,         // Max messages per consumer
    'consumers' => [
        'orderEmailConsumer',        // List of consumers to run
        'customerNotificationConsumer'
    ],
    'multiple_processes' => [
        'orderEmailConsumer' => 2    // Run 2 instances of this consumer
    ]
]
```

### Advantages of queue.xml

✅ **Pros**:
- Automatic consumer management
- No need for external tools
- Built into Magento
- Good for development/testing
- Easy setup

❌ **Cons**:
- Less control than supervisor
- Relies on cron (1-minute intervals)
- Not ideal for high-traffic sites
- Limited monitoring capabilities

### Alternative: Supervisor (Recommended for Production)

Instead of `queue.xml`, use Supervisor:

```ini
# /etc/supervisor/conf.d/magento-queue-orderEmailConsumer.conf
[program:magento-queue-orderEmailConsumer]
command=/usr/bin/php /var/www/html/magento/bin/magento queue:consumers:start orderEmailConsumer
numprocs=2
autostart=true
autorestart=true
user=www-data
```

### Should You Use queue.xml?

| Scenario | Recommendation |
|----------|---------------|
| Development | ✅ Use `queue.xml` (easy setup) |
| Testing | ✅ Use `queue.xml` |
| Small production site | ⚠️ Can use `queue.xml` |
| Large production site | ❌ Use Supervisor/Systemd |
| Docker/Kubernetes | ❌ Use separate consumer containers |
| Shared hosting | ✅ Use `queue.xml` (no supervisor access) |

### Important Notes

1. **queue.xml is NOT required** - The 4 main files are sufficient
2. **Don't mix approaches** - Choose either queue.xml OR supervisor, not both
3. **Monitor performance** - queue.xml adds overhead via cron
4. **Production best practice** - Use Supervisor or Systemd, not queue.xml

---

## File Relationships and Dependencies

### Dependency Chain

```
communication.xml (Topics)
    ↓ (topic name)
queue_topology.xml (Queue Structure)
    ↓ (topic name)
queue_publisher.xml (Publishing)
    ↓ (exchange name)
    ↓ (queue name)
queue_consumer.xml (Consuming)
    ↓ (handler)
Model/Handler.php (Processing Logic)
```

### How They Work Together

```
┌──────────────────────┐
│ communication.xml    │
│ Defines:             │
│ - Topic: my.topic    │
│ - Handler: process() │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│ queue_topology.xml   │
│ Creates:             │
│ - Exchange: magento  │
│ - Queue: my.queue    │
│ - Binding: my.topic  │
└──────────┬───────────┘
           │
     ┌─────┴─────┐
     │           │
     ▼           ▼
┌─────────┐ ┌─────────┐
│Publisher│ │Consumer │
│  .xml   │ │  .xml   │
└─────────┘ └─────────┘
     │           │
     │           ▼
     │      ┌─────────┐
     │      │ Handler │
     └─────▶│  Model  │
            └─────────┘
```

---

## Complete Example with Flow

Let's create a complete notification system step by step.

### Step 1: Create communication.xml

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Communication/etc/communication.xsd">

    <!-- Main notification topic -->
    <topic name="customer.notification.send" request="string">
        <handler name="notificationHandler"
                 type="Vendor\Module\Model\NotificationProcessor"
                 method="process"/>
    </topic>

    <!-- Dead letter topic -->
    <topic name="customer.notification.send.dead" request="string"/>
</config>
```

**Purpose**: Define that we'll send customer notifications via a topic

---

### Step 2: Create queue_topology.xml

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/topology.xsd">

    <exchange name="magento" type="topic" connection="amqp">

        <!-- Main queue -->
        <binding id="customerNotificationBinding"
                 topic="customer.notification.send"
                 destinationType="queue"
                 destination="customer.notification.queue">
            <arguments>
                <argument name="x-dead-letter-exchange" xsi:type="string">magento</argument>
                <argument name="x-dead-letter-routing-key" xsi:type="string">customer.notification.send.dead</argument>
                <argument name="x-message-ttl" xsi:type="number">3600000</argument>
            </arguments>
        </binding>

        <!-- Dead letter queue -->
        <binding id="customerNotificationDLQBinding"
                 topic="customer.notification.send.dead"
                 destinationType="queue"
                 destination="customer.notification.queue.dead"/>
    </exchange>
</config>
```

**Purpose**: Create queue infrastructure with DLQ and 1-hour message TTL

---

### Step 3: Create queue_publisher.xml

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/publisher.xsd">

    <!-- Main topic publisher -->
    <publisher topic="customer.notification.send" connection="amqp">
        <connection name="amqp" exchange="magento"/>
    </publisher>

    <!-- DLQ publisher -->
    <publisher topic="customer.notification.send.dead" connection="amqp">
        <connection name="amqp" exchange="magento"/>
    </publisher>
</config>
```

**Purpose**: Configure how to publish notification messages

---

### Step 4: Create queue_consumer.xml

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/consumer.xsd">

    <consumer name="customerNotificationConsumer"
              queue="customer.notification.queue"
              connection="amqp"
              maxMessages="50"
              consumerInstance="Magento\Framework\MessageQueue\Consumer"
              handler="Vendor\Module\Model\NotificationProcessor::process"/>
</config>
```

**Purpose**: Configure consumer to process notifications

---

### Step 5: Create Handler Model

```php
<?php
namespace Vendor\Module\Model;

class NotificationProcessor
{
    public function process(string $message): void
    {
        $data = json_decode($message, true);
        // Process notification
    }
}
```

---

### Complete Flow Visualization

```
1. Publisher Code:
   $this->publisher->publish('customer.notification.send', $jsonData);

2. queue_publisher.xml:
   Routes to AMQP connection → magento exchange

3. queue_topology.xml:
   Exchange routes to customer.notification.queue

4. Message stored in RabbitMQ queue

5. Consumer started:
   bin/magento queue:consumers:start customerNotificationConsumer

6. queue_consumer.xml:
   Reads from customer.notification.queue
   Calls NotificationProcessor::process()

7. communication.xml:
   Validates handler exists

8. Handler processes message

9a. Success → Message acknowledged and removed
9b. Failure → Message retried or sent to DLQ
```

---

## Best Practices

### 1. Naming Conventions

```
Topic Name:      entity.action.verb
Example:         order.email.send, customer.notification.send

Queue Name:      entity.action.queue
Example:         order.email.queue, customer.notification.queue

DLQ Name:        entity.action.queue.dead
Example:         order.email.queue.dead

Consumer Name:   entityActionConsumer
Example:         orderEmailConsumer, customerNotificationConsumer

Binding ID:      entityActionBinding
Example:         orderEmailBinding
```

### 2. Always Include DLQ

```xml
<binding id="myBinding" topic="my.topic" destination="my.queue">
    <arguments>
        <argument name="x-dead-letter-exchange" xsi:type="string">magento</argument>
        <argument name="x-dead-letter-routing-key" xsi:type="string">my.topic.dead</argument>
    </arguments>
</binding>
```

### 3. Set Appropriate maxMessages

```xml
<!-- For continuous processing -->
<consumer maxMessages="0"/>

<!-- For cron jobs -->
<consumer maxMessages="100"/>

<!-- For high-volume queues -->
<consumer maxMessages="1000"/>
```

### 4. Use Meaningful Handler Names

```xml
<topic name="order.email.send" request="string">
    <handler name="orderEmailHandler"     <!-- Clear name -->
             type="Vendor\Module\Model\EmailProcessor"
             method="process"/>
</topic>
```

### 5. Document Your Topics

Add comments to explain:
```xml
<!--
    Topic: order.email.send
    Purpose: Queue order confirmation emails for async processing
    Data: JSON string containing order_id, customer_email, etc.
    Handler: EmailProcessor sends email via SMTP
-->
<topic name="order.email.send" request="string">
    ...
</topic>
```

---

## Checklist for Creating Queue Functionality

- [ ] **Step 1**: Create `communication.xml`
  - [ ] Define topic name(s)
  - [ ] Set request data type
  - [ ] Define handler class and method
  - [ ] Add DLQ topic if needed

- [ ] **Step 2**: Create `queue_topology.xml`
  - [ ] Define exchange
  - [ ] Create main queue binding
  - [ ] Add DLQ configuration
  - [ ] Create DLQ binding
  - [ ] Set TTL or other arguments if needed

- [ ] **Step 3**: Create `queue_publisher.xml`
  - [ ] Configure main topic publisher
  - [ ] Set connection type (amqp/db)
  - [ ] Set exchange name
  - [ ] Configure DLQ publisher if needed

- [ ] **Step 4**: Create `queue_consumer.xml`
  - [ ] Set consumer name
  - [ ] Set queue name
  - [ ] Set connection type
  - [ ] Set maxMessages
  - [ ] Set handler

- [ ] **Step 5**: Create Handler Model
  - [ ] Implement processing logic
  - [ ] Add error handling
  - [ ] Add logging
  - [ ] Add retry logic if needed

- [ ] **Step 6**: Test
  - [ ] Run setup:upgrade
  - [ ] Check RabbitMQ queues created
  - [ ] Publish test message
  - [ ] Start consumer
  - [ ] Verify processing
  - [ ] Test failure/retry scenarios

---

## Common Issues and Solutions

### Issue 1: Topic not found
**Solution**: Ensure topic name matches in all 4 files

### Issue 2: Queue not created
**Solution**: Run `bin/magento setup:upgrade` and check RabbitMQ connection

### Issue 3: Consumer not processing
**Solution**:
- Check consumer name: `bin/magento queue:consumers:list`
- Verify handler exists
- Check logs: `var/log/system.log`

### Issue 4: Messages stuck in queue
**Solution**:
- Start consumer: `bin/magento queue:consumers:start consumerName`
- Check for errors in handler
- Verify RabbitMQ is running

---

## Summary

### File Purposes Quick Reference

| File | Defines | Example |
|------|---------|---------|
| `communication.xml` | WHAT (topics, handlers) | "order.email.send" topic exists |
| `queue_topology.xml` | WHERE (queues, structure) | "order.email.queue" storage |
| `queue_publisher.xml` | HOW TO SEND (publishing) | Publish via AMQP to magento exchange |
| `queue_consumer.xml` | HOW TO RECEIVE (consuming) | "orderEmailConsumer" processes messages |

### Remember

1. **All 4 files must be consistent** (matching topic names, queue names)
2. **Order matters during creation** (communication → topology → publisher → consumer)
3. **Always include DLQ** for production reliability
4. **Test thoroughly** before deploying
5. **Monitor queues** in production

---

## Naming Convention Reference Table

This comprehensive table shows how names and IDs should be consistent across all queue configuration files.

### Complete Naming Matrix

| Element | Format | Example | Used In Files |
|---------|--------|---------|---------------|
| **Topic Name** | `entity.action.verb` | `order.email.send` | `communication.xml`, `queue_topology.xml`, `queue_publisher.xml`, `queue.xml` |
| **Topic Name (DLQ)** | `entity.action.verb.dead` | `order.email.send.dead` | `communication.xml`, `queue_topology.xml`, `queue_publisher.xml` |
| **Handler Name** | `entityActionHandler` | `orderEmailHandler` | `communication.xml` |
| **Exchange Name** | `magento` (standard) | `magento` | `queue_topology.xml`, `queue_publisher.xml`, `queue.xml` |
| **Binding ID** | `entityActionBinding` | `orderEmailBinding` | `queue_topology.xml` |
| **Binding ID (DLQ)** | `entityActionDLQBinding` or `entityActionDeadLetterBinding` | `orderEmailDLQBinding` | `queue_topology.xml` |
| **Queue Name** | `entity.action.queue` | `order.email.queue` | `queue_topology.xml`, `queue_consumer.xml`, `queue.xml` |
| **Queue Name (DLQ)** | `entity.action.queue.dead` | `order.email.queue.dead` | `queue_topology.xml` |
| **Consumer Name** | `entityActionConsumer` | `orderEmailConsumer` | `queue_consumer.xml`, `queue.xml` |
| **Handler Class** | `Vendor\Module\Model\EntityProcessor` | `Learning\OrderEmailQueue\Model\EmailProcessor` | `communication.xml`, `queue_consumer.xml`, `queue.xml` |
| **Handler Method** | `process` (standard) | `process` | `communication.xml`, `queue_consumer.xml`, `queue.xml` |
| **Connection Name** | `amqp` or `db` | `amqp` | All files |

### Detailed Example: Order Email Queue

Here's a complete example showing all names/IDs for an Order Email Queue system:

```
Use Case: Queue order confirmation emails for async processing
Entity: order
Action: email
Verb: send
```

| File | Element | Value | Must Match |
|------|---------|-------|------------|
| **communication.xml** | Topic name | `order.email.send` | ✓ All files |
| | Handler name | `orderEmailHandler` | - |
| | Handler type | `Learning\OrderEmailQueue\Model\EmailProcessor` | ✓ queue_consumer.xml |
| | Handler method | `process` | ✓ queue_consumer.xml |
| | **DLQ Topic name** | `order.email.send.dead` | ✓ queue_topology.xml, queue_publisher.xml |
| | | | |
| **queue_topology.xml** | Exchange name | `magento` | ✓ queue_publisher.xml |
| | Binding ID (main) | `orderEmailBinding` | - |
| | Topic (main) | `order.email.send` | ✓ communication.xml |
| | Queue (main) | `order.email.queue` | ✓ queue_consumer.xml |
| | DLQ routing key | `order.email.send.dead` | ✓ communication.xml |
| | Binding ID (DLQ) | `orderEmailDeadLetterBinding` | - |
| | Topic (DLQ) | `order.email.send.dead` | ✓ communication.xml |
| | Queue (DLQ) | `order.email.queue.dead` | - |
| | Connection | `amqp` | ✓ All files |
| | | | |
| **queue_publisher.xml** | Topic | `order.email.send` | ✓ communication.xml |
| | Connection name | `amqp` | ✓ All files |
| | Exchange | `magento` | ✓ queue_topology.xml |
| | **DLQ Topic** | `order.email.send.dead` | ✓ communication.xml |
| | | | |
| **queue_consumer.xml** | Consumer name | `orderEmailConsumer` | ✓ CLI, queue.xml |
| | Queue name | `order.email.queue` | ✓ queue_topology.xml |
| | Connection | `amqp` | ✓ All files |
| | Handler | `Learning\OrderEmailQueue\Model\EmailProcessor::process` | ✓ communication.xml |
| | | | |
| **queue.xml** (optional) | Topic | `order.email.send` | ✓ communication.xml |
| | Exchange | `magento` | ✓ queue_topology.xml |
| | Queue name | `order.email.queue` | ✓ queue_consumer.xml |
| | Consumer | `orderEmailConsumer` | ✓ queue_consumer.xml |
| | Handler | `Learning\OrderEmailQueue\Model\EmailProcessor::process` | ✓ communication.xml |

### Cross-Reference Checklist

Use this checklist when creating a new queue to ensure all names match:

#### ✅ Topic Names Must Match
- [ ] `communication.xml` → `<topic name="...">`
- [ ] `queue_topology.xml` → `<binding topic="...">`
- [ ] `queue_publisher.xml` → `<publisher topic="...">`
- [ ] `queue.xml` → `<broker topic="...">` (if using)

#### ✅ Queue Names Must Match
- [ ] `queue_topology.xml` → `<binding destination="...">`
- [ ] `queue_consumer.xml` → `<consumer queue="...">`
- [ ] `queue.xml` → `<queue name="...">` (if using)

#### ✅ Exchange Names Must Match
- [ ] `queue_topology.xml` → `<exchange name="...">`
- [ ] `queue_publisher.xml` → `<connection exchange="...">`
- [ ] `queue.xml` → `<broker exchange="...">` (if using)

#### ✅ Consumer Names Must Match
- [ ] `queue_consumer.xml` → `<consumer name="...">`
- [ ] `queue.xml` → `<queue consumer="...">` (if using)
- [ ] CLI command: `bin/magento queue:consumers:start [consumerName]`

#### ✅ Handler Must Match
- [ ] `communication.xml` → `<handler type="..." method="...">`
- [ ] `queue_consumer.xml` → `<consumer handler="Class::method">`
- [ ] `queue.xml` → `<queue handler="Class::method">` (if using)

#### ✅ Connection Type Must Match
- [ ] All files should use same connection: `amqp` or `db`

### Quick Reference: Name Templates

Copy these templates when creating a new queue:

```bash
# Replace these placeholders:
# {ENTITY} = order, customer, product, etc.
# {ACTION} = email, notification, sync, etc.
# {VERB} = send, process, update, etc.
# {Vendor} = Your vendor name
# {Module} = Your module name

# Topic Name
{ENTITY}.{ACTION}.{VERB}
Example: order.email.send

# Topic Name (DLQ)
{ENTITY}.{ACTION}.{VERB}.dead
Example: order.email.send.dead

# Handler Name
{Entity}{Action}Handler
Example: orderEmailHandler

# Binding ID
{Entity}{Action}Binding
Example: orderEmailBinding

# Binding ID (DLQ)
{Entity}{Action}DeadLetterBinding
Example: orderEmailDeadLetterBinding

# Queue Name
{ENTITY}.{ACTION}.queue
Example: order.email.queue

# Queue Name (DLQ)
{ENTITY}.{ACTION}.queue.dead
Example: order.email.queue.dead

# Consumer Name
{Entity}{Action}Consumer
Example: orderEmailConsumer

# Handler Class
{Vendor}\{Module}\Model\{Entity}Processor
Example: Learning\OrderEmailQueue\Model\EmailProcessor

# Handler Method
process
```

### Real-World Examples

#### Example 1: Customer Notification Queue

```
Use Case: Send customer notifications
Entity: customer
Action: notification
Verb: send
```

| Element | Value |
|---------|-------|
| Topic | `customer.notification.send` |
| Topic (DLQ) | `customer.notification.send.dead` |
| Handler Name | `customerNotificationHandler` |
| Binding ID | `customerNotificationBinding` |
| Binding ID (DLQ) | `customerNotificationDeadLetterBinding` |
| Queue | `customer.notification.queue` |
| Queue (DLQ) | `customer.notification.queue.dead` |
| Consumer | `customerNotificationConsumer` |
| Handler Class | `Vendor\Module\Model\NotificationProcessor` |

#### Example 2: Product Sync Queue

```
Use Case: Sync products with external system
Entity: product
Action: sync
Verb: update
```

| Element | Value |
|---------|-------|
| Topic | `product.sync.update` |
| Topic (DLQ) | `product.sync.update.dead` |
| Handler Name | `productSyncHandler` |
| Binding ID | `productSyncBinding` |
| Binding ID (DLQ) | `productSyncDeadLetterBinding` |
| Queue | `product.sync.queue` |
| Queue (DLQ) | `product.sync.queue.dead` |
| Consumer | `productSyncConsumer` |
| Handler Class | `Vendor\Module\Model\SyncProcessor` |

#### Example 3: Invoice Generation Queue

```
Use Case: Generate invoices asynchronously
Entity: invoice
Action: generation
Verb: generate
```

| Element | Value |
|---------|-------|
| Topic | `invoice.generation.generate` |
| Topic (DLQ) | `invoice.generation.generate.dead` |
| Handler Name | `invoiceGenerationHandler` |
| Binding ID | `invoiceGenerationBinding` |
| Binding ID (DLQ) | `invoiceGenerationDeadLetterBinding` |
| Queue | `invoice.generation.queue` |
| Queue (DLQ) | `invoice.generation.queue.dead` |
| Consumer | `invoiceGenerationConsumer` |
| Handler Class | `Vendor\Module\Model\InvoiceProcessor` |

### Common Mistakes to Avoid

❌ **Wrong: Inconsistent naming**
```xml
<!-- communication.xml -->
<topic name="order.email.send">

<!-- queue_topology.xml -->
<binding topic="orderEmailSend">  <!-- WRONG! Format different -->
```

✅ **Correct: Consistent naming**
```xml
<!-- communication.xml -->
<topic name="order.email.send">

<!-- queue_topology.xml -->
<binding topic="order.email.send">  <!-- CORRECT! Same format -->
```

---

❌ **Wrong: Mismatched consumer name**
```xml
<!-- queue_consumer.xml -->
<consumer name="orderEmailConsumer">

<!-- CLI -->
bin/magento queue:consumers:start order_email_consumer  <!-- WRONG! -->
```

✅ **Correct: Exact consumer name**
```xml
<!-- queue_consumer.xml -->
<consumer name="orderEmailConsumer">

<!-- CLI -->
bin/magento queue:consumers:start orderEmailConsumer  <!-- CORRECT! -->
```

---

❌ **Wrong: Different queue names**
```xml
<!-- queue_topology.xml -->
<binding destination="order.email.queue">

<!-- queue_consumer.xml -->
<consumer queue="orderEmailQueue">  <!-- WRONG! Different name -->
```

✅ **Correct: Same queue name**
```xml
<!-- queue_topology.xml -->
<binding destination="order.email.queue">

<!-- queue_consumer.xml -->
<consumer queue="order.email.queue">  <!-- CORRECT! Same name -->
```

### Summary: What Must Be Unique vs What Must Match

#### Must Be UNIQUE (across all modules)
- ✓ Topic name (e.g., `order.email.send`)
- ✓ Queue name (e.g., `order.email.queue`)
- ✓ Consumer name (e.g., `orderEmailConsumer`)
- ✓ Binding ID (e.g., `orderEmailBinding`)

#### Must MATCH (across files in same module)
- ✓ Topic name → Same in all 4-5 files
- ✓ Queue name → Same in queue_topology.xml, queue_consumer.xml, queue.xml
- ✓ Exchange name → Same in queue_topology.xml, queue_publisher.xml, queue.xml
- ✓ Consumer name → Same in queue_consumer.xml, queue.xml, CLI
- ✓ Handler → Same in communication.xml, queue_consumer.xml, queue.xml
- ✓ Connection type → Same in all files (amqp or db)

#### Can Be ANYTHING (no restrictions)
- Handler name in communication.xml (e.g., `myCustomHandler`)
- Binding IDs (e.g., `myUniqueBinding123`)
- Handler class name (follows PHP naming conventions)
- Handler method name (but `process` is standard)

---

This guide should help you understand and create queue functionality in any Magento 2 module with consistent naming!
