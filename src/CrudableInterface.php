<?php

declare(strict_types=1);

namespace Erbilen\Database;

interface CrudableInterface
{
    public function create(array $data, string $tableName): int;

    public function read(int|string $id, string $tableName): mixed;

    public function update(int|string $id, string $tableName, array $data): bool;

    public function delete(int|string $id, string $tableName): bool;
}
