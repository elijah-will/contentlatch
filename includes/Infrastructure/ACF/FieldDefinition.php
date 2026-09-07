<?php
/**
 * Clean field metadata for the application. Not a raw ACF field array.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Domain\FieldRef;

final class FieldDefinition
{
    /**
     * @param array<string, string> $choices Stored value => label.
     * @param list<string>          $path Root-to-leaf ACF field keys. Empty means top-level.
     * @param list<string>          $pathNames Parallel field names for stored-value fallback.
     * @param list<string>          $pathLabels Root-to-leaf labels for breadcrumbs.
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
    ) {
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
        if (count($parts) < 2) {
            return '';
        }

        return implode(' → ', array_slice($parts, 0, -1));
    }

    public function toFieldRef(): FieldRef
    {
        return new FieldRef(
            $this->key,
            $this->name,
            $this->breadcrumb(),
            $this->path,
            $this->container,
            $this->layout
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
            $data['path']        = $this->path;
            $data['container']   = $this->container;
            $data['breadcrumb']  = $this->breadcrumb();
            $data['group_label'] = $this->groupLabel();
            $data['path_names']  = $this->pathNames;
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

        return $data;
    }
}
