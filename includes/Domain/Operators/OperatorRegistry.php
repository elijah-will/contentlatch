<?php
/**
 * Condition operator registry.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Operators;

use ContentGuard\Domain\Contracts\OperatorInterface;
use ContentGuard\Domain\Exception\UnknownOperatorException;

final class OperatorRegistry
{
    /**
     * @var array<string, OperatorInterface>
     */
    private array $operators = array();

    public function register(string $id, OperatorInterface $operator): void
    {
        $this->operators[$id] = $operator;
    }

    public function has(string $id): bool
    {
        return isset($this->operators[$id]);
    }

    public function get(string $id): OperatorInterface
    {
        if (!isset($this->operators[$id])) {
            throw new UnknownOperatorException($id);
        }

        return $this->operators[$id];
    }

    public static function v1(): self
    {
        $registry = new self();
        $registry->register('equals', new EqualsOperator());
        $registry->register('not_equals', new NotEqualsOperator());
        $registry->register('is_empty', new IsEmptyOperator());
        $registry->register('is_not_empty', new IsNotEmptyOperator());

        return $registry;
    }
}
