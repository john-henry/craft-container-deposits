<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\variables;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\errors\InvalidFieldException;
use craft\errors\SiteNotFoundException;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\models\DepositType;
use yii\base\InvalidConfigException;

/**
 * Exposed in Twig as `craft.containerDeposits.*`.
 *
 * Designed for invoice and receipt templates that need to render the
 * Re-turn-mandated layout:
 *
 *   {% set deposits = craft.containerDeposits.lineItemsFor(order) %}
 *   {% set depositTotal = craft.containerDeposits.totalFor(order) %}
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class ContainerDepositsVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the deposit line items on the order in stable order.
     *
     * @param Order $order The order to read line items from.
     * @return LineItem[] The deposit line items.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function lineItemsFor(Order $order): array
    {
        return ContainerDeposits::getInstance()->depositCart->getDepositLineItems($order);
    }

    /**
     * Returns the non-deposit (product) line items on the order.
     *
     * @param Order $order The order to read line items from.
     * @return LineItem[] The product line items.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function productLineItemsFor(Order $order): array
    {
        return ContainerDeposits::getInstance()->depositCart->getProductLineItems($order);
    }

    /**
     * Sum of price × qty across every deposit line item on the order.
     *
     * @param Order $order The order to total.
     * @return float The total deposit amount.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function totalFor(Order $order): float
    {
        return ContainerDeposits::getInstance()->depositCart->getDepositTotal($order);
    }

    /**
     * Sum of subtotals for the product (non-deposit) line items on the order.
     *
     * @param Order $order The order to total.
     * @return float The product subtotal.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function productSubtotalFor(Order $order): float
    {
        return ContainerDeposits::getInstance()->depositCart->getProductSubtotal($order);
    }

    /**
     * Returns the order's line items with products first, then deposits at the
     * bottom. Use this in cart/checkout/receipt templates so deposit lines
     * always cluster together below the product they relate to.
     *
     * @param Order $order The order to read line items from.
     * @return LineItem[] The ordered line items.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function sortedLineItemsFor(Order $order): array
    {
        $service = ContainerDeposits::getInstance()->depositCart;
        return array_merge(
            $service->getProductLineItems($order),
            $service->getDepositLineItems($order),
        );
    }

    /**
     * Total qty across product (non-deposit) line items on the order.
     * Use for cart-icon badges: shoppers count products, not the deposits
     * the plugin auto-adds.
     *
     * @param Order $order The order to total.
     * @return int The total product quantity.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function productQtyFor(Order $order): int
    {
        $qty = 0;
        foreach (ContainerDeposits::getInstance()->depositCart->getProductLineItems($order) as $item) {
            $qty += $item->qty;
        }
        return $qty;
    }

    /**
     * True if the given line item is a deposit line item.
     *
     * @param LineItem $lineItem The line item to test.
     * @return bool Whether the line item is a deposit.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function isDeposit(LineItem $lineItem): bool
    {
        return !empty($lineItem->options['_deposit']);
    }

    /**
     * Returns every deposit type configured in the CP.
     *
     * @return DepositType[] The configured deposit types.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function allTypes(): array
    {
        return ContainerDeposits::getInstance()->depositTypes->getAllDepositTypes();
    }

    /**
     * Returns the deposit type assigned to the given purchasable (variant/product),
     * or null if none.
     *
     * @param ElementInterface $purchasable The purchasable element.
     * @return DepositType|null The assigned deposit type, or null.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function typeFor(ElementInterface $purchasable): ?DepositType
    {
        return ContainerDeposits::getInstance()->depositCart->getDepositTypeForPurchasable($purchasable);
    }

    /**
     * Returns the number of deposit-bearing containers per unit of the purchasable.
     * Defaults to 1 if no `ContainerCountField` is on the field layout.
     *
     * @param ElementInterface $purchasable The purchasable element.
     * @return int The container count per unit.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function containersFor(ElementInterface $purchasable): int
    {
        return ContainerDeposits::getInstance()->depositCart->getContainerCountForPurchasable($purchasable);
    }

    /**
     * Total deposit amount for one unit of the purchasable
     * (deposit price × containers per unit).
     *
     * Returns 0.0 if the purchasable has no deposit assignment.
     *
     * @param ElementInterface $purchasable The purchasable element.
     * @return float The deposit amount for one unit.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function unitDepositFor(ElementInterface $purchasable): float
    {
        $type = $this->typeFor($purchasable);
        if (!$type) {
            return 0.0;
        }
        return (float)$type->amount * $this->containersFor($purchasable);
    }

    /**
     * Formatted "+ 15c Deposit" / "+ €1.20 Deposit" string for product cards
     * and product pages, matching Re-turn's guidance.
     *
     * Sub-€1 values render as "15c", "90c" (Re-turn's preferred style);
     * €1+ values render in the store currency.
     *
     * Returns an empty string if the purchasable has no deposit assignment.
     *
     * @param ElementInterface $purchasable The purchasable element.
     * @return string The formatted deposit display string.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function displayFor(ElementInterface $purchasable): string
    {
        $amount = $this->unitDepositFor($purchasable);
        if ($amount <= 0) {
            return '';
        }

        return Craft::t('container-deposits', '+ {amount} Deposit', [
            'amount' => $this->formatAmount($amount),
        ]);
    }

    /**
     * Render an amount in Re-turn's preferred style: cents for sub-€1,
     * currency for €1+.
     *
     * @param float $amount The amount to format.
     * @param string|null $currency The currency code, or null to use the store currency.
     * @return string The formatted amount.
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function formatAmount(float $amount, ?string $currency = null): string
    {
        if ($amount < 1) {
            // 0.15 → "15c", 0.90 → "90c"
            return round($amount * 100) . 'c';
        }

        if ($currency === null) {
            $store = Commerce::getInstance()->getStores()->getCurrentStore();
            $currency = $store->getCurrency()?->getCode() ?? 'EUR';
        }

        return Craft::$app->getFormatter()->asCurrency($amount, $currency);
    }
}
