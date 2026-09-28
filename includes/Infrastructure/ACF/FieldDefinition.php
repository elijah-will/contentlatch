<?php
/**
 * Clean field metadata for the application. Not a raw ACF field array.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\ACF;

defined('ABSPATH') || exit;

use ContentLatch\Domain\FieldRef;

final class FieldDefinition
{
    /**
     * @param array<string, string> $choices Stored value => label.
     * @param list<string>          $path Root-to-leaf ACF field keys. Empty means top-level.
     * @param list<string>          $pathNames Parallel field names for stored-value fallback.
     * @param list<string>          $pathLabels Root-to-leaf labels for breadcrumbs.
     * @param list<string>          $repeaterChain Outer-to-inner Repeater keys. Empty means none.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly array $choices = array(),
        public readonly array $path = array(),
        public readonly string $container = '',
        public readonly array $pathNames = array(),
        public readonly array $pathLabels = array(),
        public readonly string $repeaterKey = '',
        public readonly string $layout = '',
        public readonly string $layoutKey = '',
        public readonly string $layoutLabel = '',
        public readonly string $clone = '',
        public readonly string $cloneLabel = '',
        public readonly string $cloneDisplay = '',
        public readonly array $repeaterChain = array(),
        public readonly string $fieldGroup = '',
    ) {
    }

    /**
     * Outer-to-inner Repeater keys. One-level fields are a single-item list.
     *
     * @return list<string>
     */
    public function repeaterChain(): array
    {
        if ($this->repeaterChain !== array()) {
            return array_values($this->repeaterChain);
        }

        return $this->repeaterKey !== '' ? array($this->repeaterKey) : array();
    }

    public function isNestedRepeaterChild(): bool
    {
        return count($this->repeaterChain()) > 1;
    }

    /**
     * Explicit Rule Builder allowlist. The native catalog may keep
     * runtime-only leaves; Builder exposure is a separate decision.
     *
     * Supported: scalar, Repeater → scalar, and Repeater → Repeater → scalar
     * (including when the outer Repeater sits on a field group or Group).
     */
    public function isBuilderSelectable(): bool
    {
        $depth = count($this->repeaterChain());
        if ($depth > 2) {
            return false;
        }

        if ($depth === 2) {
            return $this->container === FieldRef::CONTAINER_REPEATER
                && $this->layout === ''
                && $this->clone === '';
        }

        return true;
    }

    public function resolutionId(): string
    {
        return FieldRef::resolutionIdFor($this->clone, $this->key);
    }

    public function breadcrumb(): string
    {
        if ($this->pathLabels === array()) {
            return $this->label;
        }

        if ($this->container === FieldRef::CONTAINER_FLEXIBLE && $this->layoutLabel !== '') {
            $parts = $this->pathLabels;
            $head  = array_shift($parts);

            return implode(' → ', array_values(array_filter(
                array_merge(array($head, $this->layoutLabel), $parts),
                static fn (string $part): bool => $part !== ''
            )));
        }

        return implode(' → ', $this->pathLabels);
    }

    public function groupLabel(): string
    {
        $parts = preg_split('/\s*→\s*/u', $this->breadcrumb()) ?: array();
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
        $parents = count($parts) >= 2
            ? implode(' → ', array_slice($parts, 0, -1))
            : '';

        $fieldGroup = trim($this->fieldGroup);
        if ($fieldGroup !== '') {
            return $parents !== '' ? $fieldGroup . ' → ' . $parents : $fieldGroup;
        }

        return $parents;
    }

    public function toFieldRef(): FieldRef
    {
        return new FieldRef(
            $this->key,
            $this->name,
            $this->breadcrumb(),
            $this->path,
            $this->container,
            $this->layout,
            $this->clone
        );
    }

    /**
     * Shape passed to the Rule Builder and RuleDocumentFactory.
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        $data = array(
            'key'   => $this->key,
            'name'  => $this->name,
            'label' => $this->label,
            'type'  => $this->type,
        );

        if ($this->choices !== array()) {
            $data['choices'] = $this->choices;
        }

        if ($this->path !== array()) {
            $data['path']       = $this->path;
            $data['container']  = $this->container;
            $data['breadcrumb'] = $this->breadcrumb();
            $data['path_names'] = $this->pathNames;
        }

        $groupLabel = $this->groupLabel();
        if ($groupLabel !== '') {
            $data['group_label'] = $groupLabel;
        }

        if ($this->fieldGroup !== '') {
            $data['field_group'] = $this->fieldGroup;
        }

        if ($this->container === FieldRef::CONTAINER_REPEATER) {
            $data['repeater_key'] = $this->repeaterKey;
        }

        if ($this->container === FieldRef::CONTAINER_FLEXIBLE && $this->layout !== '') {
            $data['layout'] = $this->layout;
            if ($this->layoutKey !== '') {
                $data['layout_key'] = $this->layoutKey;
            }
            if ($this->layoutLabel !== '') {
                $data['layout_label'] = $this->layoutLabel;
            }
        }

        if ($this->clone !== '') {
            $data['clone']          = $this->clone;
            $data['resolution_id']  = $this->resolutionId();
            if ($this->cloneLabel !== '') {
                $data['clone_label'] = $this->cloneLabel;
            }
            if ($this->cloneDisplay !== '') {
                $data['clone_display'] = $this->cloneDisplay;
            }
        }

        return $data;
    }
}
