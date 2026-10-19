# RabbitEvents Deduplication

Skips messages that RabbitMQ delivers again to a [RabbitEvents](https://github.com/rabbitevents/rabbitevents) listener after they were already handled. Handled message IDs are kept in a Laravel cache store shared by the workers.

RabbitEvents itself has no deduplication and needs no storage besides RabbitMQ: delivery is *at least once*. Install this package only if your service already has a cache store that all its workers share.

## When a message is delivered again

RabbitMQ delivers a message again when it didn't get the acknowledgement, e.g. the worker lost its connection (network failure, broker restart, missed heartbeats during a long listener) or was killed. What this package does in each case:

| What happened before the acknowledgement | Handled again? |
| --- | --- |
| All listeners succeeded, then the ack was lost (connection lost, worker killed) | **No**, the redelivery is skipped |
| The worker died while listeners were running | **Yes**: the message wasn't handled completely, so it is handled again. Listeners with side effects must still be idempotent |
| A listener failed and the message was retried (`--tries`) | Yes, by design. Listeners that already succeeded are skipped via `x-succeeded-listeners` (RabbitEvents core) |
| The same message reached several queues (services) | Yes, once per queue: IDs are kept per queue |
| The message was published by RabbitEvents 9.x | Yes: such messages have no ID and can't be recognized |

The RabbitMQ [message deduplication plugin](https://github.com/noxdafox/rabbitmq-message-deduplication) doesn't cover these cases: it drops duplicate *publishes*, while a redelivery isn't a publish. Remembering IDs in the worker's memory doesn't work either, because the redelivered message usually goes to another worker, and the memory of a killed worker is gone.

## Requirements

* `rabbitevents/listener` ^10.0
* A Laravel cache store **shared by all workers of a queue**: `redis`, `database` or `memcached`. `file` works only when all the workers run on one host. `array` doesn't work.

## Installation

```bash
composer require rabbitevents/deduplication
```

The service provider is discovered automatically and replaces RabbitEvents' default `NullDeduplicator`. Configure the store in `.env`:

```dotenv
RABBITEVENTS_DEDUPLICATION_STORE=redis   # empty = the default cache store
RABBITEVENTS_DEDUPLICATION_TTL=86400     # seconds a handled ID is remembered
```

To change the config file: `php artisan vendor:publish --tag=rabbitevents-deduplication`.

## When the store is not available

* **The store is not configured** (not in `config/cache.php`): `rabbitevents:listen` fails at start with `Cache store [...] is not defined.`, so a misconfiguration is noticed right away.
* **The store is unreachable at runtime** (e.g. Redis is down): messages are still handled, without deduplication, and every failed lookup or write is logged as a warning starting with `RabbitEvents deduplication:`. Messages are never dropped or blocked because of the store.

## How it works

After all listeners of a message succeeded and before the message is acknowledged, the package stores `rabbitevents:handled:{queue}:{message_id}` for `RABBITEVENTS_DEDUPLICATION_TTL` seconds. When a message arrives, the key is checked first; if it exists, the message is acknowledged without running the listeners.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

GitHub Actions (`.github/workflows/tests.yml`) runs PHPUnit on PHP 8.2–8.4 and PHPStan. Until RabbitEvents 10.0 is on Packagist, the workflow takes `rabbitevents/foundation` and `rabbitevents/listener` from the `10.x` branch of `rabbitevents/rabbitevents` (`RABBITEVENTS_REF`).

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
