<?php

declare(strict_types=1);

namespace Erbilen\Database;

abstract class CrudableAdapter implements CrudableInterface
{
    public function __construct(protected BasicDB $basicDB)
    {
    }

    public function create(array $data, string $tableName): int
    {
        $this->basicDB->insert($tableName)->set($data);
        return $this->basicDB->lastId();
    }

    public function read(int|string $id, string $tableName, string $pk = 'id'): mixed
    {
        return $this->basicDB->from($tableName)->where($pk, $id)->first();
    }

    public function update(int|string $id, string $tableName, array $data, string $pk = 'id'): bool
    {
        return $this->basicDB->update($tableName)->where($pk, $id)->set($data);
    }

    public function delete(int|string $id, string $tableName, string $pk = 'id'): bool
    {
        $result = $this->basicDB->delete($tableName)->where($pk, $id)->done();
        return $result !== false;
    }
}
