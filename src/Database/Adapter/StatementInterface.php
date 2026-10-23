<?php

declare(strict_types=1);

namespace Phoenix\Database\Adapter;

/**
 * Prepared statement of an adapter.
 *
 * It intentionally mirrors the small part of the \PDOStatement API which Phoenix itself uses,
 * so statements of every adapter can travel through the same code paths.
 */
interface StatementInterface
{
    public function getQueryString(): string;

    /**
     * @return array<array<string, mixed>>
     */
    public function fetchAll(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(): ?array;

    /**
     * Value of a single column from the next row, false when there is no row left.
     */
    public function fetchColumn(int $index = 0): mixed;
}
