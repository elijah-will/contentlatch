<?php
/**
 * CPT store that mimics WordPress post/meta slashing and type checks.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Support;

use ContentLatch\Application\Exception\RulePersistenceException;
use ContentLatch\Infrastructure\WordPress\RuleDocumentCodec;
use ContentLatch\Infrastructure\WordPress\RulePostRecord;
use ContentLatch\Infrastructure\WordPress\RulePostStoreInterface;
use ContentLatch\Infrastructure\WordPress\RulePostType;

final class WordPressLikeRulePostStore implements RulePostStoreInterface
{
    /**
     * @var array<int, array{title: string, status: string, content: string, type: string}>
     */
    public array $posts = array();

    /**
     * @var array<int, array<string, string>>
     */
    public array $meta = array();

    public int $nextId = 1;

    public int $insertCalls = 0;

    public int $updateCalls = 0;

    public int $findCalls = 0;

    public bool $failNextUpdate = false;

    public int $repeatEachFind = 1;

    public ?string $insertTypeOverride = null;

    public int $unchangedMetaWrites = 0;

    public int $healedMetaWrites = 0;

    public function insert(string $title, string $wpStatus, string $json, string $targetPostType): int
    {
        ++$this->insertCalls;
        $id = $this->nextId++;
        $this->posts[$id] = array(
            'title'   => $title,
            'status'  => $wpStatus,
            'content' => $this->slashCycle($json),
            'type'    => $this->insertTypeOverride ?? RulePostType::POST_TYPE,
        );
        $this->ensureType($id);
        $this->writeDocument($id, $json, $targetPostType);

        return $id;
    }

    public function update(int $id, string $title, string $wpStatus, string $json, string $targetPostType): void
    {
        ++$this->updateCalls;
        if ($this->failNextUpdate) {
            $this->failNextUpdate = false;
            throw new RulePersistenceException('Update failed.');
        }

        if (!isset($this->posts[$id])) {
            throw new RulePersistenceException('Rule not found.');
        }

        $this->posts[$id] = array(
            'title'   => $title,
            'status'  => $wpStatus,
            'content' => $this->slashCycle($json),
            'type'    => RulePostType::POST_TYPE,
        );
        $this->ensureType($id);
        $this->writeDocument($id, $json, $targetPostType);
    }

    public function delete(int $id): bool
    {
        if (!isset($this->posts[$id])) {
            return false;
        }

        unset($this->posts[$id], $this->meta[$id]);

        return true;
    }

    public function get(int $id): ?RulePostRecord
    {
        if (!isset($this->posts[$id]) || $this->posts[$id]['type'] !== RulePostType::POST_TYPE) {
            return null;
        }

        $post = $this->posts[$id];

        return new RulePostRecord(
            $id,
            $post['title'],
            $post['status'],
            $this->readDocument($id),
            $this->meta[$id][RulePostType::TARGET_META_KEY] ?? ''
        );
    }

    public function findByTargetPostType(string $targetPostType, bool $activeOnly): array
    {
        ++$this->findCalls;
        $matches = array();

        foreach ($this->posts as $id => $post) {
            if ($post['type'] !== RulePostType::POST_TYPE) {
                continue;
            }
            if (($this->meta[$id][RulePostType::TARGET_META_KEY] ?? '') !== $targetPostType) {
                continue;
            }
            if ($activeOnly && $post['status'] !== 'publish') {
                continue;
            }
            $record = $this->get($id);
            if ($record !== null) {
                $matches[] = $record;
            }
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

    public function findActiveTargetPostTypes(): array
    {
        $types = array();
        foreach ($this->posts as $id => $post) {
            if ($post['type'] !== RulePostType::POST_TYPE || $post['status'] !== 'publish') {
                continue;
            }
            $type = $this->meta[$id][RulePostType::TARGET_META_KEY] ?? '';
            if ($type !== '') {
                $types[$type] = $type;
            }
        }

        return array_values($types);
    }

    public function findAll(): array
    {
        $records = array();
        foreach (array_keys($this->posts) as $id) {
            $record = $this->get((int) $id);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function seedRaw(int $id, string $title, string $status, string $content, string $targetPostType, ?string $documentMeta): void
    {
        $this->posts[$id] = array(
            'title'   => $title,
            'status'  => $status,
            'content' => $content,
            'type'    => RulePostType::POST_TYPE,
        );
        $this->meta[$id][RulePostType::TARGET_META_KEY] = $targetPostType;
        if ($documentMeta !== null) {
            $this->meta[$id][RulePostType::DOCUMENT_META_KEY] = $documentMeta;
        }
        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }
    }

    private function writeDocument(int $id, string $json, string $targetPostType): void
    {
        $this->writeMeta($id, RulePostType::DOCUMENT_META_KEY, $json);
        $this->writeMeta($id, RulePostType::TARGET_META_KEY, $targetPostType);

        if (RuleDocumentCodec::fromRawStorage($this->meta[$id][RulePostType::DOCUMENT_META_KEY] ?? null) === null) {
            throw new RulePersistenceException('Could not store the rule document.');
        }
    }

    private function writeMeta(int $id, string $key, string $value): void
    {
        $payload = $key === RulePostType::DOCUMENT_META_KEY
            ? RuleDocumentCodec::forStoredMeta($value)
            : $value;
        $stored = stripslashes($payload);

        $existing = $this->meta[$id][$key] ?? null;
        if ($existing === $stored) {
            ++$this->unchangedMetaWrites;

            return;
        }

        $this->meta[$id][$key] = $stored;
    }

    private function ensureType(int $id): void
    {
        if (($this->posts[$id]['type'] ?? '') === RulePostType::POST_TYPE) {
            return;
        }

        $this->posts[$id]['type'] = RulePostType::POST_TYPE;
    }

    private function readDocument(int $id): string
    {
        $canonical = RuleDocumentCodec::fromRawStorage($this->meta[$id][RulePostType::DOCUMENT_META_KEY] ?? null);
        if ($canonical !== null) {
            return $canonical;
        }

        $content = $this->posts[$id]['content'] ?? '';
        $recovered = RuleDocumentCodec::fromRawStorage($content);
        if ($recovered === null) {
            return $content;
        }

        try {
            $this->writeMeta($id, RulePostType::DOCUMENT_META_KEY, $recovered);
            ++$this->healedMetaWrites;
        } catch (RulePersistenceException) {
            return $recovered;
        }

        return $recovered;
    }

    private function slashCycle(string $json): string
    {
        return stripslashes(addslashes('<p>' . $json . '</p>'));
    }
}
