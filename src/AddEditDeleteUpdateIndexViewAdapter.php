<?php

declare(strict_types=1);

namespace Erbilen\Database;

abstract class AddEditDeleteUpdateIndexViewAdapter extends CrudableAdapter implements AddEditDeleteUpdateIndexViewInterface
{
    public function __construct(BasicDB $connection, public string $tableName)
    {
        parent::__construct($connection);
    }

    public function add(array $data): int
    {
        return parent::create($data, $this->tableName);
    }

    public function edit(int|string $id, array $data): bool
    {
        return parent::update($id, $this->tableName, $data);
    }

    public function index(): iterable
    {
        foreach ($this->basicDB->from($this->tableName)->all() as $row) {
            yield $row;
        }
    }

    public function view(int|string $id): mixed
    {
        return parent::read($id, $this->tableName);
    }

    public function delete(int|string $id, string $tableName = '', string $pk = 'id'): bool
    {
        return parent::delete($id, $tableName !== '' ? $tableName : $this->tableName, $pk);
    }
}
