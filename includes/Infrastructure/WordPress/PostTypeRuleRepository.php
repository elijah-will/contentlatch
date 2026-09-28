<?php
/**
 * Hidden CPT rule repository.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

use ContentLatch\Application\Exception\RulePersistenceException;
use ContentLatch\Application\RuleDocumentValidator;
use ContentLatch\Application\RuleMutationPresentation;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Domain\Exception\InvalidRuleException;
use ContentLatch\Domain\Rule;
use ContentLatch\Domain\RuleStatus;

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

    /**
     * Safe facts about why find() missed a saved ID. No document bodies.
     *
     * @return array<string, mixed>
     */
    public function describeRead(int $id): array
    {
        $record = $this->store->get($id);
        $info = array(
            'id'            => $id,
            'store_hit'     => $record !== null,
            'hydrate_ok'    => false,
            'hydrate_error' => '',
        );

        if ($this->store instanceof WpRulePostStore) {
            $info = array_merge($this->store->inspect($id), $info);
        }

        if ($record === null) {
            return $info;
        }

        try {
            $json = RuleDocumentCodec::recover($record->json) ?? $record->json;
            $this->validator->decode($json);
            $info['hydrate_ok'] = true;
        } catch (InvalidRuleException $exception) {
            $info['hydrate_error'] = $exception->getMessage();
        }

        return $info;
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
        $json = RuleDocumentCodec::recover($record->json) ?? $record->json;

        try {
            $rule = $this->validator->decode($json);
        } catch (InvalidRuleException $exception) {
            RuleMutationPresentation::logFailure('rule document could not be read for ID ' . $record->id, $exception);

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
        $data = $rule->toArray();
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Non-WP unit-test fallback when wp_json_encode is unavailable.
            : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
