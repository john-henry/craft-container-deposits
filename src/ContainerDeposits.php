<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\SiteEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Sites;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\fields\ContainerCountField;
use johnhenry\containerdeposits\fields\DepositTypeField;
use johnhenry\containerdeposits\jobs\ResaveDepositPurchasables;
use johnhenry\containerdeposits\services\DepositCartService;
use johnhenry\containerdeposits\services\DepositTypeService;
use johnhenry\containerdeposits\variables\ContainerDepositsVariable;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\ModelEvent;

/**
 * Container Deposits plugin.
 *
 * Adds container deposits to Craft Commerce for any deposit return scheme,
 * Ireland's Re-turn or your own region's equivalent: configurable deposit
 * types, synthetic deposit purchasables, automatic cart line-item syncing, and
 * Re-turn-ready invoice/receipt rendering.
 *
 * @property-read DepositTypeService $depositTypes
 * @property-read null|array $cpNavItem
 * @property-read DepositCartService $depositCart
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class ContainerDeposits extends BasePlugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var ContainerDeposits The plugin instance.
     */
    public static ContainerDeposits $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether the plugin has a CP section.
     */
    public bool $hasCpSection = true;

    /**
     * @var string The plugin's schema version.
     */
    public string $schemaVersion = '1.0.0';

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The plugin's component configuration.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'depositTypes' => DepositTypeService::class,
                'depositCart' => DepositCartService::class,
            ],
        ];
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->_registerElementTypes();
        $this->_registerFieldTypes();
        $this->_registerCartListeners();
        $this->_registerTwigVariable();
        $this->_registerMultiSiteHandlers();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpUrlRules();
        }
    }

    /**
     * Returns the deposit types service.
     *
     * @return DepositTypeService The deposit types service.
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function getDepositTypes(): DepositTypeService
    {
        $component = $this->get('depositTypes');
        assert($component instanceof DepositTypeService);
        return $component;
    }

    /**
     * Returns the deposit cart service.
     *
     * @return DepositCartService The deposit cart service.
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function getDepositCart(): DepositCartService
    {
        $component = $this->get('depositCart');
        assert($component instanceof DepositCartService);
        return $component;
    }

    /**
     * @inheritdoc
     *
     * @return array|null The CP nav item definition.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('container-deposits', 'Container Deposits');
        $item['subnav'] = [
            'deposit-types' => [
                'label' => Craft::t('container-deposits', 'Deposit Types'),
                'url' => 'container-deposits',
            ],
        ];
        return $item;
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the plugin's element types.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = DepositPurchasable::class;
            }
        );
    }

    /**
     * Registers the plugin's field types.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = DepositTypeField::class;
                $event->types[] = ContainerCountField::class;
            }
        );
    }

    /**
     * Registers the order save listener that syncs deposit line items.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerCartListeners(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_BEFORE_SAVE,
            function(ModelEvent $event) {
                /** @var Order $order */
                $order = $event->sender;
                $this->getDepositCart()->syncDepositLineItems($order);
            }
        );
    }

    /**
     * Registers the `craft.containerDeposits` Twig variable.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('containerDeposits', ContainerDepositsVariable::class);
            }
        );
    }

    /**
     * Re-propagates every DepositPurchasable when a new site is added, so the
     * cart sync can find its deposits on the new site right away. The resave
     * runs on the queue rather than inline, so adding a site doesn't hang on
     * however many purchasables and sites are in play.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerMultiSiteHandlers(): void
    {
        Event::on(
            Sites::class,
            Sites::EVENT_AFTER_SAVE_SITE,
            function(SiteEvent $event) {
                if (!$event->isNew) {
                    return;
                }
                Craft::$app->getQueue()->push(new ResaveDepositPurchasables());
            }
        );
    }

    /**
     * Registers the plugin's control panel URL rules.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules = array_merge([
                    'container-deposits' => 'container-deposits/deposit-types/index',
                    'container-deposits/new' => 'container-deposits/deposit-types/edit',
                    'container-deposits/<id:\d+>' => 'container-deposits/deposit-types/edit',
                    'container-deposits/save' => 'container-deposits/deposit-types/save',
                    'container-deposits/delete' => 'container-deposits/deposit-types/delete',
                ], $event->rules);
            }
        );
    }
}
