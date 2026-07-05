<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\services;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\errors\InvalidFieldException;
use craft\errors\SiteNotFoundException;
use johnhenry\containerdeposits\fields\ContainerCountField;
use johnhenry\containerdeposits\fields\DepositTypeField;
use johnhenry\containerdeposits\models\DepositType;
use yii\base\InvalidConfigException;

/**
 * Deposit cart service.
 *
 * Keeps an order's deposit line items reconciled with its product line items:
 * adds, updates, and removes synthetic deposit lines on every order save, and
 * exposes the read helpers that the front-end Twig variable uses to render
 * Re-turn-compliant invoice and receipt layouts.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositCartService extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * @var array<int|string, bool> Guards against re-entrant sync calls, keyed by order ID.
     */
    private static array $_syncing = [];

    // Public Methods
    // =========================================================================

    /**
     * Reconciles the order's deposit line items to match its current product
     * line items. Called from `Order::EVENT_BEFORE_SAVE`.
     *
     * @param Order $order The order being saved.
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function syncDepositLineItems(Order $order): void
    {
        $orderId = $order->id ?? 'new';

        if (isset(self::$_syncing[$orderId])) {
            return;
        }

        // Never modify completed orders
        if ($order->isCompleted) {
            return;
        }

        self::$_syncing[$orderId] = true;

        try {
            $this->_doSync($order);
        } finally {
            unset(self::$_syncing[$orderId]);
        }
    }

    /**
     * Returns the deposit line items on the order.
     *
     * @param Order $order The order to read line items from.
     * @return LineItem[] The deposit line items.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDepositLineItems(Order $order): array
    {
        $deposits = [];
        foreach ($order->getLineItems() as $item) {
            if (!empty($item->options['_deposit'])) {
                $deposits[] = $item;
            }
        }
        return $deposits;
    }

    /**
     * Returns the non-deposit line items (i.e. the actual products).
     *
     * @param Order $order The order to read line items from.
     * @return LineItem[] The product line items.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getProductLineItems(Order $order): array
    {
        $products = [];
        foreach ($order->getLineItems() as $item) {
            if (empty($item->options['_deposit'])) {
                $products[] = $item;
            }
        }
        return $products;
    }

    /**
     * Sum of price × qty across every deposit line item on the order.
     *
     * Used to render the "Total Re-turn Deposit" subtotal mandated by
     * Re-turn's producer invoice guidance.
     *
     * @param Order $order The order to total.
     * @return float The total deposit amount.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDepositTotal(Order $order): float
    {
        $total = 0.0;
        foreach ($this->getDepositLineItems($order) as $item) {
            $total += $item->price * $item->qty;
        }
        return $total;
    }

    /**
     * Sum of subtotals for the non-deposit (product) line items.
     *
     * @param Order $order The order to total.
     * @return float The product subtotal.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getProductSubtotal(Order $order): float
    {
        $total = 0.0;
        foreach ($this->getProductLineItems($order) as $item) {
            $total += $item->getSubtotal();
        }
        return $total;
    }

    /**
     * Returns the deposit type assigned to a purchasable via its field layout,
     * or null if no DepositTypeField is set / populated.
     *
     * @param ElementInterface $purchasable The purchasable element.
     * @return DepositType|null The assigned deposit type, or null.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function getDepositTypeForPurchasable(ElementInterface $purchasable): ?DepositType
    {
        $fieldLayout = $purchasable->getFieldLayout();
        if (!$fieldLayout) {
            return null;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($field instanceof DepositTypeField) {
                $value = $purchasable->getFieldValue($field->handle);
                if ($value instanceof DepositType) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Returns the container count (deposits per unit) for a purchasable.
     * Defaults to 1 if no ContainerCountField is on the field layout.
     *
     * @param ElementInterface $purchasable The purchasable element.
     * @return int The container count per unit.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function getContainerCountForPurchasable(ElementInterface $purchasable): int
    {
        $fieldLayout = $purchasable->getFieldLayout();
        if (!$fieldLayout) {
            return 1;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($field instanceof ContainerCountField) {
                $value = $purchasable->getFieldValue($field->handle);
                return max(1, (int)$value);
            }
        }

        return 1;
    }

    // Private Methods
    // =========================================================================

    /**
     * Performs the deposit line item reconciliation for a single order.
     *
     * @param Order $order The order to reconcile.
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _doSync(Order $order): void
    {
        $allItems = $order->getLineItems();
        $regularItems = [];
        $depositItems = [];

        foreach ($allItems as $item) {
            if (!empty($item->options['_deposit'])) {
                $depositItems[] = $item;
            } else {
                $regularItems[] = $item;
            }
        }

        // The same purchasable can show up on more than one line, so cache the
        // field lookups for this pass instead of scanning its layout each time.
        $depositTypeCache = [];
        $containerCountCache = [];

        // Work out what deposits the cart should have, keyed by deposit type.
        // Qty is the product qty times the containers per unit (1 by default).
        $expectedDeposits = [];
        foreach ($regularItems as $item) {
            $depositType = $this->_getDepositTypeForLineItem($item, $depositTypeCache);
            if (!$depositType || !$depositType->purchasableId) {
                continue;
            }
            $containers = $this->_getContainerCountForLineItem($item, $containerCountCache);
            $key = $depositType->id;
            if (!isset($expectedDeposits[$key])) {
                $expectedDeposits[$key] = ['type' => $depositType, 'qty' => 0];
            }
            $expectedDeposits[$key]['qty'] += $item->qty * $containers;
        }

        // Grab the current qty of each deposit line before we touch anything.
        // These are the same objects we mutate below, so if we don't take a
        // plain-scalar copy now, the "did the qty change?" check later always
        // comes back equal.
        $originalQtyByDepositTypeId = [];
        foreach ($depositItems as $depositItem) {
            $depositTypeId = (int)($depositItem->options['_depositTypeId'] ?? 0);
            if ($depositTypeId) {
                $originalQtyByDepositTypeId[$depositTypeId] = $depositItem->qty;
            }
        }

        // Match the deposit lines we already have to what's expected, fixing
        // the qty as we go. Any deposit line with no matching product is left
        // out on purpose, that's how orphans get dropped.
        $reconciledDeposits = [];
        foreach ($depositItems as $depositItem) {
            $depositTypeId = (int)($depositItem->options['_depositTypeId'] ?? 0);
            if ($depositTypeId && isset($expectedDeposits[$depositTypeId])) {
                $depositItem->qty = $expectedDeposits[$depositTypeId]['qty'];
                $reconciledDeposits[$depositTypeId] = $depositItem;
                unset($expectedDeposits[$depositTypeId]);
            }
        }

        // Anything still left in $expectedDeposits needs a brand new line.
        foreach ($expectedDeposits as $depositTypeId => $data) {
            /** @var DepositType $depositType */
            $depositType = $data['type'];
            $lineItem = $this->_createDepositLineItem($order, $depositType, $data['qty']);
            if ($lineItem) {
                $reconciledDeposits[$depositTypeId] = $lineItem;
            }
        }

        // Only write the line items back if the set actually changed.
        $newItems = array_merge($regularItems, array_values($reconciledDeposits));
        if (count($newItems) !== count($allItems)) {
            $order->setLineItems($newItems);
            return;
        }

        // Same count, so check whether any deposit qty moved against the
        // snapshot we took earlier.
        foreach ($reconciledDeposits as $depositTypeId => $depositItem) {
            $originalQty = $originalQtyByDepositTypeId[$depositTypeId] ?? null;
            if ($originalQty === null || $originalQty !== $depositItem->qty) {
                $order->setLineItems($newItems);
                return;
            }
        }
    }

    /**
     * Resolves the deposit type assigned to a line item's purchasable, reusing a
     * per-sync cache keyed by purchasable ID.
     *
     * @param LineItem $lineItem The line item to inspect.
     * @param array<int, DepositType|null> $cache Per-sync deposit-type cache, keyed by purchasable ID.
     * @return DepositType|null The assigned deposit type, or null.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    private function _getDepositTypeForLineItem(LineItem $lineItem, array &$cache): ?DepositType
    {
        $purchasable = $lineItem->getPurchasable();
        if (!$purchasable instanceof ElementInterface) {
            return null;
        }

        $id = $purchasable->id;
        if ($id !== null && array_key_exists($id, $cache)) {
            return $cache[$id];
        }

        $depositType = $this->getDepositTypeForPurchasable($purchasable);
        if ($id !== null) {
            $cache[$id] = $depositType;
        }

        return $depositType;
    }

    /**
     * Resolves the container count for a line item's purchasable, reusing a
     * per-sync cache keyed by purchasable ID.
     *
     * @param LineItem $lineItem The line item to inspect.
     * @param array<int, int> $cache Per-sync container-count cache, keyed by purchasable ID.
     * @return int The container count per unit.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    private function _getContainerCountForLineItem(LineItem $lineItem, array &$cache): int
    {
        $purchasable = $lineItem->getPurchasable();
        if (!$purchasable instanceof ElementInterface) {
            return 1;
        }

        $id = $purchasable->id;
        if ($id !== null && array_key_exists($id, $cache)) {
            return $cache[$id];
        }

        $count = $this->getContainerCountForPurchasable($purchasable);
        if ($id !== null) {
            $cache[$id] = $count;
        }

        return $count;
    }

    /**
     * Creates a deposit line item for the given deposit type and quantity.
     *
     * @param Order $order The order the line item belongs to.
     * @param DepositType $depositType The deposit type to create a line item for.
     * @param int $qty The deposit quantity.
     * @return LineItem|null The created line item, or null if it can't be created.
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    private function _createDepositLineItem(Order $order, DepositType $depositType, int $qty): ?LineItem
    {
        if (!$depositType->purchasableId) {
            return null;
        }

        $options = [
            '_deposit' => true,
            '_depositTypeId' => $depositType->id,
        ];

        return Commerce::getInstance()->getLineItems()->create($order, [
            'purchasableId' => $depositType->purchasableId,
            'options' => $options,
            'qty' => $qty,
        ]);
    }
}
