<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Tests\Support;

use Psr\Container\ContainerInterface;

/**
 * A minimal PSR-11 container with PHP-DI's `set()`: entries are values or
 * factories taking the container, resolved once.
 */
final class TestContainer implements ContainerInterface
{
    /** @var array<string,mixed> */
    private array $entries = [];

    /** @var array<string,mixed> */
    private array $resolved = [];

    /** @param array<string,mixed> $entries */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    public function set(string $id, mixed $value): void
    {
        $this->entries[$id] = $value;
        unset($this->resolved[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }
        if (!array_key_exists($id, $this->entries)) {
            throw new \RuntimeException("unbound: {$id}");
        }
        $entry = $this->entries[$id];
        return $this->resolved[$id] = $entry instanceof \Closure ? $entry($this) : $entry;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
