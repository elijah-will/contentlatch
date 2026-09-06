<?php
/**
 * Hidden CPT rule repository.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

use ContentGuard\Application\Exception\RulePersistenceException;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleStatus;

final class PostTypeRuleRepository implements RuleRepositoryInterface
{
    /**
     * @var array<string, Rule[]>
     */
    private array $activeCache = array();

    public function __construct(
        private RulePostStoreInterface $store,
        private RuleDocumentValidator $validator,
    ) {
    }

    public static function wordpress(): self
    {
        return new self(new WpRulePostStore(), RuleDocumentValidator::v1());
    }

    public function findActiveForPostType(string $postType): array
    {
        if (!array_key_exists($postType, $this->activeCache)) {
            $this->activeCache[$postType] = $this->loadActive($postType);
        }

        return $this->activeCache[$postType];
    }

    public function findActivePostTypes(): array
    {
        return $this->store->findActiveTargetPostTypes();
    }

    public function find(int|string $id): ?Rule
    {
        $record = $this->store->get((int) $id);
        if ($record === null) {
            return null;
        }

        return $this->hydrate($record);
    }

    public function findAll(): array
    {
        $rules = array();

        foreach ($this->store->findAll() as $record) {
            if (isset($rules[$record->id])) {
                continue;
            }

            $rule = $this->hydrate($record);
            if ($rule !== null) {
                $rules[$record->id] = $rule;
            }
        }

        return array_values($rules);
    }

    public function save(Rule $rule): Rule
    {
        $this->validator->validateRule($rule);

        $wpStatus = $rule->status === RuleStatus::Active ? 'publish' : 'draft';

        if ($this->isBlankId($rule->id)) {
            $id = $this->store->insert($rule->name, $wpStatus, $this->encode($rule), $rule->postType);
            $stamped = $this->stampId($rule, $id);
            try {
                $this->store->update($id, $stamped->name, $wpStatus, $this->encode($stamped), $stamped->postType);
            } catch (RulePersistenceException $exception) {
                $this->store->delete($id);
                throw $exception;
            }
            $this->invalidateCache();

            return $stamped;
        }

        $id = (int) $rule->id;
        if ($this->store->get($id) === null) {
            throw new RulePersistenceException('Rule not found.');
        }

        $this->store->update($id, $rule->name, $wpStatus, $this->encode($rule), $rule->postType);
        $this->invalidateCache();

        return $rule;
    }

    public function delete(int|string $id): bool
    {
        $deleted = $this->store->delete((int) $id);
        if ($deleted) {
            $this->invalidateCache();
        }

        return $deleted;
    }

    /**
     * @return Rule[]
     */
    private function loadActive(string $postType): array
    {
        $rules = array();

        foreach ($this->store->findByTargetPostType($postType, true) as $record) {
            if (isset($rules[$record->id])) {
                continue;
            }

            $rule = $this->hydrate($record);
            if ($rule !== null && $rule->status === RuleStatus::Active) {
                $rules[$record->id] = $rule;
            }
        }

        return array_values($rules);
    }

    private function hydrate(RulePostRecord $record): ?Rule
    {
        try {
            $rule = $this->validator->decode($record->json);
        } catch (InvalidRuleException) {
            return null;
        }

        if ((int) $rule->id !== $record->id) {
            $rule = $this->stampId($rule, $record->id);
        }

        return $rule;
    }

    private function stampId(Rule $rule, int $id): Rule
    {
        $data       = $rule->toArray();
        $data['id'] = $id;

        return Rule::fromArray($data);
    }

    private function encode(Rule $rule): string
    {
        $json = json_encode($rule->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RulePersistenceException('Could not serialize rule document.');
        }

        return $json;
    }

    private function isBlankId(int|string $id): bool
    {
        return $id === '' || $id === 0 || $id === '0';
    }

    private function invalidateCache(): void
    {
        $this->activeCache = array();
    }
}
