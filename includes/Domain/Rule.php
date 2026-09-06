<?php
/**
 * A content quality rule.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

use ContentGuard\Domain\Exception\InvalidRuleException;

final class Rule
{
    public const SCHEMA_VERSION = 1;
    public const CONDITION_LOGIC_AND = 'and';

    /**
     * @param Condition[]  $conditions
     * @param Validation[] $validations
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $name,
        public readonly string $postType,
        public readonly RuleStatus $status,
        public readonly RuleSeverity $severity,
        public readonly array $conditions,
        public readonly array $validations,
        public readonly int $schemaVersion = self::SCHEMA_VERSION,
        public readonly string $conditionLogic = self::CONDITION_LOGIC_AND,
        public readonly ?string $updatedAt = null,
        public readonly ?string $createdAt = null,
    ) {
        if ($this->name === '') {
            throw new InvalidRuleException('Rule name is required.');
        }

        if ($this->postType === '') {
            throw new InvalidRuleException('Rule post type is required.');
        }

        if ($this->conditionLogic !== self::CONDITION_LOGIC_AND) {
            throw new InvalidRuleException('Only AND condition logic is supported.');
        }

        foreach ($this->conditions as $condition) {
            if (!$condition instanceof Condition) {
                throw new InvalidRuleException('Rule conditions must be Condition instances.');
            }
        }

        foreach ($this->validations as $validation) {
            if (!$validation instanceof Validation) {
                throw new InvalidRuleException('Rule validations must be Validation instances.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'schema_version'  => $this->schemaVersion,
            'id'              => $this->id,
            'name'            => $this->name,
            'post_type'       => $this->postType,
            'status'          => $this->status->value,
            'severity'        => $this->severity->value,
            'condition_logic' => $this->conditionLogic,
            'conditions'      => array_map(
                static fn (Condition $condition): array => $condition->toArray(),
                $this->conditions
            ),
            'validations'     => array_map(
                static fn (Validation $validation): array => $validation->toArray(),
                $this->validations
            ),
            'updated_at'      => $this->updatedAt,
            'created_at'      => $this->createdAt,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $conditions = array();
        foreach (self::arrayOfMaps($data['conditions'] ?? array(), 'conditions') as $condition) {
            $conditions[] = Condition::fromArray($condition);
        }

        $validations = array();
        foreach (self::arrayOfMaps($data['validations'] ?? array(), 'validations') as $validation) {
            $validations[] = Validation::fromArray($validation);
        }

        $status   = (string) ($data['status'] ?? RuleStatus::Active->value);
        $severity = (string) ($data['severity'] ?? RuleSeverity::Fail->value);

        $statusEnum = RuleStatus::tryFrom($status);
        if ($statusEnum === null) {
            throw new InvalidRuleException(sprintf('Invalid rule status "%s".', $status));
        }

        $severityEnum = RuleSeverity::tryFrom($severity);
        if ($severityEnum === null) {
            throw new InvalidRuleException(sprintf('Invalid rule severity "%s".', $severity));
        }

        $id = $data['id'] ?? '';
        if (!is_int($id) && !is_string($id)) {
            throw new InvalidRuleException('Rule id must be an integer or string.');
        }

        return new self(
            $id,
            (string) ($data['name'] ?? ''),
            (string) ($data['post_type'] ?? ''),
            $statusEnum,
            $severityEnum,
            $conditions,
            $validations,
            (int) ($data['schema_version'] ?? self::SCHEMA_VERSION),
            (string) ($data['condition_logic'] ?? self::CONDITION_LOGIC_AND),
            isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            isset($data['created_at']) ? (string) $data['created_at'] : null,
        );
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private static function arrayOfMaps(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new InvalidRuleException(sprintf('Rule %s must be an array.', $label));
        }

        $maps = array();
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new InvalidRuleException(sprintf('Rule %s entries must be objects.', $label));
            }
            $maps[] = $item;
        }

        return $maps;
    }
}
