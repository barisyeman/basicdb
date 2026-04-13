<?php

declare(strict_types=1);

namespace Erbilen\Database;

interface AddEditDeleteUpdateIndexViewInterface
{
    public function add(array $data): int;

    public function edit(int|string $id, array $data): bool;

    public function index(): iterable;

    public function view(int|string $id): mixed;

    public function delete(int|string $id): bool;
}
