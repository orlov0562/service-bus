<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation)
 * Supports Saga pattern and Event Sourcing
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus\Scheduler\Store\Sql;

use function Amp\asyncCall;
use function Amp\call;
use Amp\Promise;
use function Desperado\ServiceBus\Common\datetimeToString;
use Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation;
use Desperado\ServiceBus\Scheduler\Data\ScheduledOperation;
use Desperado\ServiceBus\Scheduler\Exceptions\ScheduledOperationNotFound;
use Desperado\ServiceBus\Scheduler\ScheduledOperationId;
use Desperado\ServiceBus\Scheduler\Store\SchedulerStore;
use function Desperado\ServiceBus\Infrastructure\Storage\fetchOne;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\deleteQuery;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\equalsCriteria;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\insertQuery;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\selectQuery;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\updateQuery;
use Desperado\ServiceBus\Infrastructure\Storage\StorageAdapter;
use Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter;

/**
 *
 */
final class SqlSchedulerStore implements SchedulerStore
{
    /**
     * @var StorageAdapter
     */
    private $adapter;

    /**
     * @param StorageAdapter $adapter
     */
    public function __construct(StorageAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @inheritDoc
     */
    public function add(ScheduledOperation $operation, callable $postAdd): Promise
    {
        $adapter = $this->adapter;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(ScheduledOperation $operation, callable $postAdd) use ($adapter): \Generator
            {
                /** @var \Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter $transaction */
                $transaction = yield $adapter->transaction();

                try
                {
                    /** Save new entry */

                    /** @psalm-suppress ImplicitToStringCast */
                    $insertQuery = insertQuery('scheduler_registry', [
                        'id'              => (string) $operation->id(),
                        'processing_date' => datetimeToString($operation->date()),
                        'command'         => \base64_encode(\serialize($operation->command())),
                        'is_sent'         => (int) $operation->isSent()
                    ]);

                    $compiledQuery = $insertQuery->compile();

                    /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                    $resultSet = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());

                    unset($insertQuery, $compiledQuery, $resultSet);

                    /** Receive next operation and notification about the scheduled job  */

                    /** @var \Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation|null $nextOperation */
                    $nextOperation = yield self::fetchNextOperation($transaction);

                    asyncCall($postAdd, $operation, $nextOperation);

                    yield $transaction->commit();

                    unset($nextOperation);
                }
                catch(\Throwable $throwable)
                {
                    yield $transaction->rollback();

                    throw $throwable;
                }
                finally
                {
                    unset($transaction);
                }
            },
            $operation, $postAdd
        );
    }

    /**
     * @inheritDoc
     */
    public function remove(ScheduledOperationId $id, callable $postRemove): Promise
    {
        $adapter = $this->adapter;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(ScheduledOperationId $id, callable $postRemove) use ($adapter): \Generator
            {
                /** @var \Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter $transaction */
                $transaction = yield $adapter->transaction();

                try
                {
                    /** @psalm-suppress ImplicitToStringCast */
                    $deleteQuery = deleteQuery('scheduler_registry')
                        ->where(equalsCriteria('id', $id));

                    $compiledQuery = $deleteQuery->compile();

                    /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                    $resultSet = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());

                    unset($deleteQuery, $compiledQuery, $resultSet);

                    /** @var \Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation|null $nextOperation */
                    $nextOperation = yield self::fetchNextOperation($transaction);

                    asyncCall($postRemove, $nextOperation);

                    yield $transaction->commit();

                    unset($nextOperation);
                }
                catch(\Throwable $throwable)
                {
                    yield $transaction->rollback();

                    throw $throwable;
                }
                finally
                {
                    unset($transaction);
                }
            },
            $id, $postRemove
        );
    }

    /**
     * @inheritDoc
     */
    public function extract(ScheduledOperationId $id, callable $postExtract): Promise
    {
        $adapter = $this->adapter;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(ScheduledOperationId $id, callable $postExtract) use ($adapter): \Generator
            {
                /** @var \Desperado\ServiceBus\Scheduler\Data\ScheduledOperation|null $operation */
                $operation = yield self::doLoadOperation($adapter, $id);

                /** Scheduled operation not found */
                if(null === $operation)
                {
                    throw new ScheduledOperationNotFound(
                        \sprintf('Operation with ID "%s" not found', $id)
                    );
                }

                /** @var \Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter $transaction */
                $transaction = yield $adapter->transaction();

                try
                {
                    /** @psalm-suppress ImplicitToStringCast */
                    $deleteQuery = deleteQuery('scheduler_registry')
                        ->where(equalsCriteria('id', $id));

                    $compiledQuery = $deleteQuery->compile();

                    /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                    $resultSet = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());

                    unset($deleteQuery, $compiledQuery, $resultSet);

                    /** @var \Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation|null $nextOperation */
                    $nextOperation = yield self::fetchNextOperation($transaction);

                    asyncCall($postExtract, $operation, $nextOperation);

                    yield $transaction->commit();

                    unset($nextOperation);
                }
                catch(\Throwable $throwable)
                {
                    yield $transaction->rollback();

                    throw $throwable;
                }
                finally
                {
                    unset($transaction);
                }
            },
            $id, $postExtract
        );
    }

    /**
     * @param TransactionAdapter $transaction
     *
     * @return Promise<\Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation|null>
     */
    private static function fetchNextOperation(TransactionAdapter $transaction): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function() use ($transaction): \Generator
            {
                /** @var \Latitude\QueryBuilder\Query\SelectQuery $selectQuery */
                $selectQuery = selectQuery('scheduler_registry')
                    ->where(equalsCriteria('is_sent', 0))
                    ->orderBy('processing_date', 'ASC')
                    ->limit(1);

                $compiledQuery = $selectQuery->compile();

                /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                $resultSet = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());

                /** @var array|null $result */
                $result = yield fetchOne($resultSet);

                unset($selectQuery, $compiledQuery, $resultSet);

                if(true === \is_array($result) && 0 !== \count($result))
                {
                    /** Update barrier flag */

                    /** @var \Latitude\QueryBuilder\Query\UpdateQuery $updateQuery */
                    $updateQuery = updateQuery('scheduler_registry', ['is_sent' => 1])
                        ->where(equalsCriteria('id', $result['id']))
                        ->andWhere(equalsCriteria('is_sent', 0));

                    $compiledQuery = $updateQuery->compile();

                    /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                    $resultSet    = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());
                    $affectedRows = $resultSet->affectedRows();

                    unset($updateQuery, $compiledQuery, $resultSet);

                    if(0 !== $affectedRows)
                    {
                        return NextScheduledOperation::fromRow($result);
                    }
                }
            }
        );
    }

    /**
     * @param StorageAdapter       $adapter
     * @param ScheduledOperationId $id
     *
     * @return Promise<\Desperado\ServiceBus\Scheduler\Data\ScheduledOperation|null>
     */
    private static function doLoadOperation(StorageAdapter $adapter, ScheduledOperationId $id): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(ScheduledOperationId $id) use ($adapter): \Generator
            {
                $operation = null;

                /** @psalm-suppress ImplicitToStringCast */
                $selectQuery = selectQuery('scheduler_registry')
                    ->where(equalsCriteria('id', $id));

                $compiledQuery = $selectQuery->compile();

                /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                $resultSet = yield $adapter->execute($compiledQuery->sql(), $compiledQuery->params());

                /** @var array|null $result */
                $result = yield fetchOne($resultSet);

                unset($selectQuery, $compiledQuery, $resultSet);

                if(true === \is_array($result) && 0 !== \count($result))
                {
                    $result['command'] = $adapter->unescapeBinary($result['command']);

                    $operation = ScheduledOperation::restoreFromRow($result);
                }

                return $operation;
            },
            $id
        );
    }
}
