<?php
/**
 * Plugin bootstrap.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard;

defined('ABSPATH') || exit;

use ContentGuard\Admin\AuditAjaxController;
use ContentGuard\Admin\AuditPage;
use ContentGuard\Admin\EditorAuditNotice;
use ContentGuard\Admin\EditorFieldFocus;
use ContentGuard\Admin\EditorRestBlockNotice;
use ContentGuard\Admin\RuleEditorDraftStore;
use ContentGuard\Admin\RulesController;
use ContentGuard\Admin\RulesPage;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\IncomingSaveEvaluator;
use ContentGuard\Application\Integration\CompositeFieldCatalog;
use ContentGuard\Application\Integration\CompositeValueProvider;
use ContentGuard\Application\Integration\FieldCatalog;
use ContentGuard\Application\Integration\IntegrationRegistry;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Infrastructure\ACF\AcfSaveValidator;
use ContentGuard\Infrastructure\ACF\SaveWarningNotifier;
use ContentGuard\Infrastructure\WordPress\AuditSchema;
use ContentGuard\Infrastructure\WordPress\Capabilities;
use ContentGuard\Infrastructure\WordPress\CoreIntegration;
use ContentGuard\Infrastructure\WordPress\CoreSaveValidator;
use ContentGuard\Infrastructure\WordPress\EditablePostTypes;
use ContentGuard\Infrastructure\WordPress\PostTypeRuleRepository;
use ContentGuard\Infrastructure\WordPress\RestSaveValidator;
use ContentGuard\Infrastructure\WordPress\RulePostType;

final class Plugin
{
    public const VERSION      = '1.0.0';
    public const MIN_PHP      = '8.1';
    public const MIN_WP       = '6.6';
    public const MIN_ACF      = '6.0.0';
    public const TEXT_DOMAIN  = 'contentguard';
    public const SLUG         = 'contentguard';

    private static ?self $instance = null;

    private Dependencies $dependencies;

    private ?CoreIntegration $coreIntegration = null;

    private ?AcfIntegration $acfIntegration = null;

    private ?IntegrationRegistry $integrations = null;

    private ?FieldCatalog $fieldCatalog = null;

    private ?RuleRepositoryInterface $ruleRepository = null;

    private ?ContentAuditService $auditService = null;

    private ?RuleCommandService $ruleCommands = null;

    private ?RuleDocumentFactory $ruleFactory = null;

    private ?IncomingSaveEvaluator $incomingSaveEvaluator = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(new Dependencies());
        }

        return self::$instance;
    }

    public function __construct(Dependencies $dependencies)
    {
        $this->dependencies = $dependencies;
    }

    public function boot(): void
    {
        $this->loadTextDomain();
        $this->dependencies->registerAdminNotices();
        Capabilities::grant();
        add_action('init', array(RulePostType::class, 'register'));
        add_action('admin_init', array(AuditSchema::class, 'install'));

        $ruleDrafts = RuleEditorDraftStore::wordpress();
        $incoming   = $this->incomingSaveEvaluator();

        AcfSaveValidator::register($this->ruleRepository(), $incoming);
        RestSaveValidator::register($incoming);
        CoreSaveValidator::register($incoming);
        EditorFieldFocus::register();
        EditorRestBlockNotice::register();
        EditorAuditNotice::register($this->auditService(), $this->ruleRepository());
        SaveWarningNotifier::register(
            $this->ruleRepository(),
            $this->fieldCatalog(),
            function (int $postId, string $postType, array $fieldTypes): CompositeValueProvider {
                return new CompositeValueProvider(array(
                    $this->coreIntegration()->storedProvider($postId, $postType, $fieldTypes),
                    $this->acfIntegration()->storedProvider($postId, $postType, $fieldTypes),
                ));
            }
        );
        RulesPage::register($this->ruleRepository(), $this->ruleFactory(), $ruleDrafts, $this->auditService());
        RulesController::register($this->ruleRepository(), $this->ruleCommands(), $this->ruleFactory(), $ruleDrafts);
        AuditPage::register($this->auditService(), $this->ruleRepository());
        AuditAjaxController::register($this->auditService());
    }

    public function ruleCommands(): RuleCommandService
    {
        if ($this->ruleCommands === null) {
            $this->ruleCommands = new RuleCommandService(
                $this->ruleRepository(),
                array(Capabilities::class, 'currentUserCanManage'),
                static function (string $nonce): bool {
                    return (bool) wp_verify_nonce($nonce, RuleCommandService::NONCE_ACTION);
                }
            );
        }

        return $this->ruleCommands;
    }

    public function ruleFactory(): RuleDocumentFactory
    {
        if ($this->ruleFactory === null) {
            $catalog = $this->fieldCatalog();
            $this->ruleFactory = RuleDocumentFactory::v1(
                array(EditablePostTypes::class, 'choices'),
                $catalog
            );
        }

        return $this->ruleFactory;
    }

    public function auditService(): ContentAuditService
    {
        if ($this->auditService === null) {
            $core    = $this->coreIntegration();
            $acf     = $this->acfIntegration();
            $catalog = $this->fieldCatalog();
            $this->auditService = ContentAuditService::wordpress(
                $this->ruleRepository(),
                $catalog,
                static function (int $postId, string $postType, array $fieldTypes) use ($core, $acf): CompositeValueProvider {
                    return new CompositeValueProvider(array(
                        $core->storedProvider($postId, $postType, $fieldTypes),
                        $acf->storedProvider($postId, $postType, $fieldTypes),
                    ));
                }
            );
        }

        return $this->auditService;
    }

    public function integrations(): IntegrationRegistry
    {
        $this->ensureIntegrations();

        return $this->integrations;
    }

    public function fieldCatalog(): FieldCatalog
    {
        $this->ensureIntegrations();

        return $this->fieldCatalog;
    }

    public function coreIntegration(): CoreIntegration
    {
        $this->ensureIntegrations();

        return $this->coreIntegration;
    }

    public function acfIntegration(): AcfIntegration
    {
        $this->ensureIntegrations();

        return $this->acfIntegration;
    }

    public function dependencies(): Dependencies
    {
        return $this->dependencies;
    }

    public function ruleRepository(): RuleRepositoryInterface
    {
        if ($this->ruleRepository === null) {
            $this->ruleRepository = PostTypeRuleRepository::wordpress();
        }

        return $this->ruleRepository;
    }

    public function incomingSaveEvaluator(): IncomingSaveEvaluator
    {
        if ($this->incomingSaveEvaluator === null) {
            $this->incomingSaveEvaluator = new IncomingSaveEvaluator(
                RuleEngine::v1(),
                $this->ruleRepository(),
                $this->fieldCatalog(),
                $this->coreIntegration(),
                $this->acfIntegration()
            );
        }

        return $this->incomingSaveEvaluator;
    }

    private function ensureIntegrations(): void
    {
        if ($this->integrations !== null) {
            return;
        }

        $this->coreIntegration = CoreIntegration::wordpress();
        $this->acfIntegration  = AcfIntegration::wordpress($this->dependencies);
        $this->integrations    = new IntegrationRegistry(array(
            $this->coreIntegration->descriptor(),
            $this->acfIntegration->descriptor(),
        ));
        $this->fieldCatalog = new CompositeFieldCatalog(array(
            $this->coreIntegration,
            $this->acfIntegration,
        ));
    }

    private function loadTextDomain(): void
    {
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname(plugin_basename(CONTENTGUARD_FILE)) . '/languages'
        );
    }
}
