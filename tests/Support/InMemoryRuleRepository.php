<?php
/**
 * In-memory rule repository for tests.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Support;

use ContentGuard\Application\Exception\RulePersistenceException;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleStatus;

final class InMemoryRuleRepository implements RuleRepositoryInterface
{
    private int $nextId = 1;

    /**
     * @param Rule[] $rules
     */
    public function __construct(
        private array $rules = array(),
        private ?RuleDocumentValidator $validator = null,
    ) {
        foreach ($this->rules as $rule) {
            if (is_int($rule->id) && $rule->id >= $this->nextId) {
                $this->nextId = $rule->id + 1;
            }
        }
    }

    public function findActiveForPostType(string $postType): array
    {
        $matches = array();

        foreach ($this->rules as $rule) {
            if ($rule->status !== RuleStatus::Active) {
                continue;
            }

            if ($rule->postType !== $postType) {
                continue;
            }

            $matches[] = $rule;
        }

        return $matches;
    }

    public function findActivePostTypes(): array
    {
        $types = array();

        foreach ($this->rules as $rule) {
            if ($rule->status !== RuleStatus::Active) {
                continue;
            }

            $types[$rule->postType] = $rule->postType;
        }

        return array_values($types);
    }

    public function find(int|string $id): ?Rule
    {
        foreach ($this->rules as $rule) {
            if ((string) $rule->id === (string) $id) {
                return $rule;
            }
        }

        return null;
    }

    public function findAll(): array
    {
        return array_values($this->rules);
    }

    public function save(Rule $rule): Rule
    {
        if ($this->validator !== null) {
            $this->validator->validateRule($rule);
        }

        if ($rule->id === '' || $rule->id === 0 || $rule->id === '0') {
            $data       = $rule->toArray();
            $data['id'] = $this->nextId++;
            $rule       = Rule::fromArray($data);
            $this->rules[] = $rule;

            return $rule;
        }

        foreach ($this->rules as $index => $existing) {
            if ((string) $existing->id === (string) $rule->id) {
                $this->rules[$index] = $rule;

                return $rule;
            }
        }

        throw new RulePersistenceException('Rule not found.');
    }

    public function delete(int|string $id): bool
    {
        foreach ($this->rules as $index => $rule) {
            if ((string) $rule->id === (string) $id) {
                unset($this->rules[$index]);
                $this->rules = array_values($this->rules);

                return true;
            }
        }

        return false;
    }
}
