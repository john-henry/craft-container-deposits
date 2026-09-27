<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\base;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\events\AddLineItemEvent;
use craft\commerce\events\MatchOrderEvent;
use craft\commerce\events\OrderLineItemsRefreshEvent;
use craft\commerce\services\Discounts;
use craft\events\DefineGqlTypeFieldsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\SiteEvent;
use craft\gql\TypeManager;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Sites;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\fields\ContainerCountField;
use johnhenry\containerdeposits\fields\DepositTypeField;
use johnhenry\containerdeposits\gql\types\ContainerDeposit;
use johnhenry\containerdeposits\jobs\ResaveDepositPurchasables;
use johnhenry\containerdeposits\variables\ContainerDepositsVariable;
use yii\base\Event;
use yii\base\ModelEvent;

/**
 * PluginTrait
 *
 * Wires the plugin's event listeners, control panel URL rules and nav item.
 * Keeps the main plugin class a thin shell: the listeners defined here only
 * hand work to the services and never carry domain logic themselves.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
trait PluginTrait
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array|null The CP nav item definition.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * Registers the order listeners that keep deposit line items in step with
     * the products.
     *
     * Deposits are synced as the order saves, and again once Commerce has
     * refreshed its line items (which can drop a line or cut its quantity to
     * the stock left), so the adjusters that follow see the right deposits.
     * An order saved with recalculation switched off is left exactly as it is.
     * Deposit purchasables can't be added to an order directly: the sync adds
     * them, and it doesn't go through the add-line-item path.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
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
                if ($order->getRecalculationMode() === Order::RECALCULATION_MODE_NONE) {
                    return;
                }
                $this->getDepositCart()->syncDepositLineItems($order);
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_LINE_ITEMS_REFRESHED,
            function(OrderLineItemsRefreshEvent $event) {
                /** @var Order $order */
                $order = $event->sender;
                $order->setLineItems($event->lineItems);
                $this->getDepositCart()->syncDepositLineItems($order);
                $event->lineItems = $order->getLineItems();
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_BEFORE_ADD_LINE_ITEM,
            function(AddLineItemEvent $event) {
                if ($this->getDepositCart()->isDepositLineItem($event->lineItem)) {
                    $event->isValid = false;
                }
            }
        );
    }

    /**
     * Keeps deposit lines from counting toward a discount's minimum purchase
     * total or quantity.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _registerDiscountListeners(): void
    {
        Event::on(
            Discounts::class,
            Discounts::EVENT_DISCOUNT_MATCHES_ORDER,
            function(MatchOrderEvent $event) {
                if (!$event->isValid) {
                    return;
                }
                if (!$this->getDepositCart()->meetsDiscountThresholds($event->order, $event->discount)) {
                    $event->isValid = false;
                }
            }
        );
    }

    /**
     * Adds the `containerDeposit` field to Commerce's variant types in GraphQL.
     *
     * The interface and every variant type are both extended, since a query
     * can ask for the field through either.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _registerGqlFields(): void
    {
        Event::on(
            TypeManager::class,
            TypeManager::EVENT_DEFINE_GQL_TYPE_FIELDS,
            static function(DefineGqlTypeFieldsEvent $event) {
                if ($event->typeName === 'VariantInterface' || str_ends_with($event->typeName, '_Variant')) {
                    $event->fields[ContainerDeposit::FIELD] = ContainerDeposit::fieldDefinition();
                }
            }
        );
    }

    /**
     * Registers the `craft.containerDeposits` Twig variable.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * Resaves every DepositPurchasable whenever a site is saved, so a store
     * that comes in with a new site gets the purchasables' store records. The
     * resave runs on the queue rather than inline, so saving a site doesn't
     * hang on however many purchasables and sites are in play.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerMultiSiteHandlers(): void
    {
        Event::on(
            Sites::class,
            Sites::EVENT_AFTER_SAVE_SITE,
            function(SiteEvent $event) {
                Craft::$app->getQueue()->push(new ResaveDepositPurchasables());
            }
        );
    }

    /**
     * Registers the plugin's control panel URL rules.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
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
