<?php
/**
 * Incoming save-evaluation composition for tests.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Support;

use ContentLatch\Application\IncomingSaveEvaluator;
use ContentLatch\Application\Integration\CompositeFieldCatalog;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Infrastructure\ACF\AcfIntegration;
use ContentLatch\Infrastructure\WordPress\CoreIntegration;

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
