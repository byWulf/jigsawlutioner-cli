<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

class MessengerRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @throws DbalException
     */
    public function getQueuedMessagesCount(string $queueName): int
    {
        return (int) $this->connection->executeQuery(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queueName',
            ['queueName' => $queueName]
        )->fetchOne();
    }

    /**
     * @throws DbalException
     */
    public function clearQueue(string $queueName): void
    {
        $this->connection->executeStatement(
            'DELETE FROM messenger_messages WHERE queue_name = :queueName',
            ['queueName' => $queueName]
        );
    }
}
