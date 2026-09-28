<?php
/**
 * In-memory CPT store for repository tests.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Support;

use ContentLatch\Application\Exception\RulePersistenceException;
use ContentLatch\Infrastructure\WordPress\RulePostRecord;
use ContentLatch\Infrastructure\WordPress\RulePostStoreInterface;

final class FakeRulePostStore implements RulePostStoreInterface
{
    /**
     * @var array<int, RulePostRecord>
     */
    public array $records = array();

    public int $nextId = 1;

    public int $findCalls = 0;

    public int $insertCalls = 0;

    public int $updateCalls = 0;

    public bool $failNextUpdate = false;

    public int $repeatEachFind = 1;

    public function insert(string $title, string $wpStatus, string $json, string $targetPostType): int
    {
        ++$this->insertCalls;
        $id = $this->nextId++;
        $this->records[$id] = new RulePostRecord($id, $title, $wpStatus, $json, $targetPostType);

        return $id;
    }

    public function update(int $id, string $title, string $wpStatus, string $json, string $targetPostType): void
    {
        ++$this->updateCalls;
        if ($this->failNextUpdate) {
            $this->failNextUpdate = false;
            throw new RulePersistenceException('Update failed.');
        }

        if (!isset($this->records[$id])) {
            throw new RulePersistenceException('Rule not found.');
        }

        $this->records[$id] = new RulePostRecord($id, $title, $wpStatus, $json, $targetPostType);
    }

    public function delete(int $id): bool
    {
        if (!isset($this->records[$id])) {
            return false;
        }

        unset($this->records[$id]);

        return true;
    }

    public function get(int $id): ?RulePostRecord
    {
        return $this->records[$id] ?? null;
    }

    public function findByTargetPostType(string $targetPostType, bool $activeOnly): array
    {
        ++$this->findCalls;
        $matches = array();

        foreach ($this->records as $record) {
            if ($record->targetPostType !== $targetPostType) {
                continue;
            }

            if ($activeOnly && $record->wpStatus !== 'publish') {
                continue;
            }

            $matches[] = $record;
        }

        if ($this->repeatEachFind <= 1) {
            return $matches;
        }

        $repeated = array();
        foreach ($matches as $record) {
            for ($i = 0; $i < $this->repeatEachFind; $i++) {
                $repeated[] = $record;
            }
        }

        return $repeated;
    }

    public function findAll(): array
    {
        $records = array();

        foreach ($this->records as $record) {
            if ($record->wpStatus === 'trash') {
                continue;
            }

            $records[] = $record;
        }

        return array_values($records);
    }

    public function findActiveTargetPostTypes(): array
    {
        $types = array();

        foreach ($this->records as $record) {
            if ($record->wpStatus !== 'publish') {
                continue;
            }

            $types[$record->targetPostType] = $record->targetPostType;
        }

        return array_values($types);
    }

    public function seed(RulePostRecord $record): void
    {
        $this->records[$record->id] = $record;
        if ($record->id >= $this->nextId) {
            $this->nextId = $record->id + 1;
        }
    }
}
