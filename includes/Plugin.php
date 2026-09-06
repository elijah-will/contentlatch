<?php
/**
 * Plugin bootstrap.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard;

use ContentGuard\Admin\AuditAjaxController;
use ContentGuard\Admin\AuditPage;
use ContentGuard\Admin\EditorAuditNotice;
use ContentGuard\Admin\EditorFieldFocus;
use ContentGuard\Admin\RuleEditorDraftStore;
use ContentGuard\Admin\RulesController;
use ContentGuard\Admin\RulesPage;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfSaveValidator;
use ContentGuard\Infrastructure\ACF\SaveWarningNotifier;
use ContentGuard\Infrastructure\WordPress\AuditSchema;
use ContentGuard\Infrastructure\WordPress\Capabilities;
use ContentGuard\Infrastructure\WordPress\EditablePostTypes;
use ContentGuard\Infrastructure\WordPress\PostTypeRuleRepository;
use ContentGuard\Infrastructure\WordPress\RulePostType;

final class Plugin
{
    public const VERSION      = '0.1.0';
    public const MIN_PHP      = '8.1';
    public const MIN_WP       = '6.6';
    public const MIN_ACF      = '6.0.0';
    public const TEXT_DOMAIN  = 'contentguard';
    public const SLUG         = 'contentguard';

    private static ?self $instance = null;

    private Dependencies $dependencies;

    private ?RuleRepositoryInterface $ruleRepository = null;

    private ?ContentAuditService $auditService = null;

    private ?RuleCommandService $ruleCommands = null;

    private ?RuleDocumentFactory $ruleFactory = null;

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

        AcfSaveValidator::register($this->ruleRepository());
        EditorFieldFocus::register();
        EditorAuditNotice::register($this->auditService(), $this->ruleRepository());
        SaveWarningNotifier::register($this->ruleRepository());
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
            $catalog = new AcfFieldCatalog();
            $this->ruleFactory = RuleDocumentFactory::v1(
                array(EditablePostTypes::class, 'choices'),
                static function (string $postType) use ($catalog): array {
                    $fields = array();
                    foreach ($catalog->fieldsForPostType($postType) as $field) {
                        $fields[] = array(
                            'key'   => $field->key,
                            'name'  => $field->name,
                            'label' => $field->label,
                            'type'  => $field->type,
                        );
                    }

                    return $fields;
                }
            );
        }

        return $this->ruleFactory;
    }

    public function auditService(): ContentAuditService
    {
        if ($this->auditService === null) {
            $this->auditService = ContentAuditService::wordpress($this->ruleRepository());
        }

        return $this->auditService;
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

    private function loadTextDomain(): void
    {
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname(plugin_basename(CONTENTGUARD_FILE)) . '/languages'
        );
    }
}
