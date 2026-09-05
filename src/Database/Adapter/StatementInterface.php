<?php

declare(strict_types=1);

namespace Phoenix\Database\Adapter;

/**
 * Prepared statement of an adapter which is not built on top of PDO.
 *
 * It intentionally mirrors the small part of the PDOStatement API which Phoenix itself uses,
 * so both kinds of statements can travel through the same code paths.
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
}
