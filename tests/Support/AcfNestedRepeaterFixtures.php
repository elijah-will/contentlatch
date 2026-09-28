<?php
/**
 * In-memory Repeater → Repeater → scalar fixtures (Phase 15A).
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Support;

use ContentLatch\Domain\FieldRef;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;

final class AcfNestedRepeaterFixtures
{
    public const DIRECTIONS = 'field_directions';
    public const STEPS      = 'field_steps';
    public const STEP_NAME  = 'field_step_name';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recipeFields(): array
    {
        return array(
            array(
                'key'        => self::DIRECTIONS,
                'name'       => 'directions',
                'label'      => 'Directions',
                'type'       => 'repeater',
                'sub_fields' => array(
                    array(
                        'key'        => self::STEPS,
                        'name'       => 'steps',
                        'label'      => 'Steps',
                        'type'       => 'repeater',
                        'sub_fields' => array(
                            array(
                                'key'   => self::STEP_NAME,
                                'name'  => 'name',
                                'label' => 'Name',
                                'type'  => 'text',
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    public static function recipeCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                return $postType === 'recipe' ? self::recipeFields() : array();
            }
        );
    }

    public static function stepNameRef(): FieldRef
    {
        return new FieldRef(
            self::STEP_NAME,
            'name',
            'Directions → Steps → Name',
            array(self::DIRECTIONS, self::STEPS, self::STEP_NAME),
            FieldRef::CONTAINER_REPEATER
        );
    }

    /**
     * @return list<string>
     */
    public static function path(): array
    {
        return array(self::DIRECTIONS, self::STEPS, self::STEP_NAME);
    }

    /**
     * @return list<string>
     */
    public static function pathNames(): array
    {
        return array('directions', 'steps', 'name');
    }

    /**
     * @return list<string>
     */
    public static function chain(): array
    {
        return array(self::DIRECTIONS, self::STEPS);
    }
}
