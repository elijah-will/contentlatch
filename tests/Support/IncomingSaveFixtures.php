<?php
/**
 * Incoming save-evaluation composition for tests.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Support;

use ContentGuard\Application\IncomingSaveEvaluator;
use ContentGuard\Application\Integration\CompositeFieldCatalog;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Infrastructure\WordPress\CoreIntegration;

final class IncomingSaveFixtures
{
    public static function evaluator(
        RuleRepositoryInterface $repository,
        AcfFieldCatalog $acfCatalog,
        ?CoreIntegration $core = null,
    ): IncomingSaveEvaluator {
        $core ??= CoreIntegration::wordpress();
        $acf  = new AcfIntegration($acfCatalog);

        return new IncomingSaveEvaluator(
            RuleEngine::v1(),
            $repository,
            new CompositeFieldCatalog(array($core, $acf)),
            $core,
            $acf
        );
    }
}
