<?php

namespace App\Plugins\Db;

use App\Plugins\Db\Connection\IConnection;
use PDO;
use PDOException;
use PDOStatement;

class Db implements IDb
{
    /** @var PDO|null */
    private $connection = null;
    /** @var PDOStatement */
    private $stmt;

    public function __construct(IConnection $connectionImplementation)
    {
        $this->connection = $this->connect($connectionImplementation);
    }

    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    public function rollBack()
    {
        return $this->connection->rollBack();
    }

    public function commit()
    {
        return $this->connection->commit();
    }

    public function executeQuery(string $query, array $bind = []): bool
    {
        $this->stmt = $this->connection->prepare($query);
        if ($bind) {
            return $this->stmt->execute($bind);
        }
        return $this->stmt->execute();
    }

    public function fetch(): array|false
    {
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetchAll(): array
    {
        return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLastInsertedId($name = null): int
    {
        $id = $this->connection->lastInsertId($name);
        return ($id ?: false);
    }

    public function getConnection(): ?PDO
    {
        return $this->connection;
    }

    private function connect(IConnection $connectionImplementation): PDO
    {
        try {
            $pdo = new PDO(
                $connectionImplementation->getDsn(),
                $connectionImplementation->getUsername(),
                $connectionImplementation->getPassword(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            return $pdo;
        } catch (PDOException $e) {
            throw $e;
        }
    }

    public function getStatement(): ?PDOStatement
    {
        return $this->stmt;
    }
}
