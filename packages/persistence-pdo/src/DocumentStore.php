<?php
declare(strict_types=1);
namespace Corevia\Persistence;

/** JSON-compatible application documents with explicit string IDs; not a SQL emulation. */
interface DocumentStore
{
    public function get(string $collection,string $id): ?array;
    public function put(string $collection,string $id,array $document): void;
    public function delete(string $collection,string $id): bool;
    public function find(string $collection,array $equals=[],int $limit=100): array;
}
