<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\enums\LineItemType;
use craft\commerce\models\Discount;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\errors\InvalidFieldException;
use craft\errors\SiteNotFoundException;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\events\DefineLineItemContentsEvent;
use johnhenry\containerdeposits\fields\ContainerCountField;
use johnhenry\containerdeposits\fields\DepositTypeField;
use johnhenry\containerdeposits\models\DepositType;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * Deposit cart service.
 *
 * Keeps an order's deposit line items reconciled with its product line items:
 * adds, updates, and removes synthetic deposit lines on every order save, and
 * exposes the read helpers that the front-end Twig variable uses to render
 * Re-turn-compliant invoice and receipt layouts.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositCartService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @event DefineLineItemContentsEvent The event that is triggered for each product line item while an order's
     * deposits are reconciled, so a plugin can list the purchasables packed inside it (the cans in a bundle, say).
     * @since 1.1.0
     */
    public const EVENT_DEFINE_LINE_ITEM_CONTENTS = 'defineLineItemContents';

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
     * line items. Called while the order is saved and after its line items are
     * refreshed.
     *
     * @param Order $order The order being saved.
     * @return void
     * @throws InvalidConfigException If a line item's purchasable or the deposit types can't be resolved.
     * @throws InvalidFieldException If a deposit field can't be read.
     * @throws SiteNotFoundException If the order's site can't be resolved while creating a deposit line.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws InvalidConfigException If a line item's purchasable can't be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDepositLineItems(Order $order): array
    {
        $deposits = [];
        foreach ($order->getLineItems() as $item) {
            if ($this->isDepositLineItem($item)) {
                $deposits[] = $item;
            }
        }
        return $deposits;
    }

    /**
     * Whether a line item is one of the plugin's own deposit lines.
     *
     * A line pointing at one of the configured deposit purchasables is a
     * deposit line, and one pointing at anything else that still resolves is
     * not; line item options come off the add-to-cart request, so they never
     * decide it. The `_deposit` option only counts on a completed order whose
     * deposit type has since been deleted, where nothing can be posted.
     *
     * @param LineItem $lineItem The line item to classify.
     * @return bool Whether it is a deposit line.
     * @throws InvalidConfigException If the deposit types service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function isDepositLineItem(LineItem $lineItem): bool
    {
        if ($lineItem->type !== LineItemType::Purchasable) {
            return false;
        }

        $purchasableId = (int)$lineItem->purchasableId;
        if ($purchasableId !== 0 && isset($this->_getDepositTypeIdsByPurchasableId()[$purchasableId])) {
            return true;
        }

        if ($lineItem->getPurchasable() !== null) {
            return false;
        }

        return $lineItem->getOrder()?->isCompleted === true && !empty($lineItem->options['_deposit']);
    }

    /**
     * Returns the non-deposit line items (i.e. the actual products).
     *
     * @param Order $order The order to read line items from.
     * @return LineItem[] The product line items.
     * @throws InvalidConfigException If a line item's purchasable can't be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getProductLineItems(Order $order): array
    {
        $products = [];
        foreach ($order->getLineItems() as $item) {
            if (!$this->isDepositLineItem($item)) {
                $products[] = $item;
            }
        }
        return $products;
    }

    /**
     * Sum of the deposit line items' subtotals: what the order charges in
     * deposits.
     *
     * Used to render the "Total Re-turn Deposit" subtotal mandated by
     * Re-turn's producer invoice guidance.
     *
     * @param Order $order The order to total.
     * @return float The total deposit amount.
     * @throws InvalidConfigException If a line item's purchasable can't be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDepositTotal(Order $order): float
    {
        $total = 0.0;
        foreach ($this->getDepositLineItems($order) as $item) {
            $total += $item->getSubtotal();
        }
        return $total;
    }

    /**
     * Sum of subtotals for the non-deposit (product) line items.
     *
     * @param Order $order The order to total.
     * @return float The product subtotal.
     * @throws InvalidConfigException If a line item's purchasable can't be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * Whether an order still meets a discount's minimum purchase total and
     * quantity once its deposit lines are left out.
     *
     * Commerce counts every line toward those thresholds, so without this six
     * cans and their six deposits would pass a "12 items or more" condition.
     * Only discounts that apply to all purchasables are affected; the others
     * count matching lines, which deposits never are.
     *
     * @param Order $order The order being matched.
     * @param Discount $discount The discount being matched.
     * @return bool Whether the thresholds are met on the products alone.
     * @throws InvalidConfigException If a line item's purchasable can't be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function meetsDiscountThresholds(Order $order, Discount $discount): bool
    {
        if (!$discount->allPurchasables || !$discount->allCategories) {
            return true;
        }

        if ($discount->purchaseQty <= 0 && $discount->purchaseTotal <= 0) {
            return true;
        }

        $qty = 0;
        $subtotal = 0.0;
        foreach ($this->getProductLineItems($order) as $item) {
            $qty += $item->qty;
            $subtotal += $item->getSubtotal();
        }

        if ($discount->purchaseTotal > 0 && $subtotal < $discount->purchaseTotal) {
            return false;
        }

        return !($discount->purchaseQty > 0 && $qty < $discount->purchaseQty);
    }

    /**
     * Returns the deposit type assigned to a purchasable, or null if it has
     * none.
     *
     * Read from the purchasable's own field layout first. When that layout has
     * no deposit type field and the purchasable is a variant, the product's
     * layout is checked instead, so the field works on either.
     *
     * @param ElementInterface $purchasable The purchasable element.
     * @return DepositType|null The assigned deposit type, or null.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function getDepositTypeForPurchasable(ElementInterface $purchasable): ?DepositType
    {
        foreach ($this->_getFieldSources($purchasable) as $element) {
            $field = $this->_findField($element, DepositTypeField::class);
            if ($field) {
                $value = $element->getFieldValue($field->handle);
                return $value instanceof DepositType ? $value : null;
            }
        }

        return null;
    }

    /**
     * Returns the container count (deposits per unit) for a purchasable.
     *
     * Read from the same layout as the deposit type: the purchasable's own,
     * then its product's. Defaults to 1 when neither has a container count
     * field.
     *
     * @param ElementInterface $purchasable The purchasable element.
     * @return int The container count per unit.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function getContainerCountForPurchasable(ElementInterface $purchasable): int
    {
        foreach ($this->_getFieldSources($purchasable) as $element) {
            $field = $this->_findField($element, ContainerCountField::class);
            if ($field) {
                return max(1, (int)$element->getFieldValue($field->handle));
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
     * @throws InvalidConfigException If a line item's purchasable can't be resolved.
     * @throws InvalidFieldException If a deposit field can't be read.
     * @throws SiteNotFoundException If the order's site can't be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _doSync(Order $order): void
    {
        $allItems = $order->getLineItems();
        $regularItems = [];
        $depositItems = [];

        foreach ($allItems as $item) {
            if ($this->isDepositLineItem($item)) {
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
        // Each line's own purchasable counts once per unit, and anything packed
        // inside it counts as many times as the contents say. Qty is the line
        // qty times that count times the containers per unit (1 by default).
        $expectedDeposits = [];
        foreach ($regularItems as $item) {
            foreach ($this->_getDepositablesForLineItem($item) as [$purchasable, $perUnit]) {
                $depositType = $this->_getDepositTypeCached($purchasable, $depositTypeCache);
                if (!$depositType || !$depositType->purchasableId) {
                    continue;
                }
                $containers = $this->_getContainerCountCached($purchasable, $containerCountCache);
                $key = $depositType->id;
                if (!isset($expectedDeposits[$key])) {
                    $expectedDeposits[$key] = ['type' => $depositType, 'qty' => 0];
                }
                $expectedDeposits[$key]['qty'] += $item->qty * $perUnit * $containers;
            }
        }

        // A deposit line's type comes from the purchasable it points at. Its
        // options are posted by the customer, so they're never trusted here.
        $typeIdsByPurchasableId = $this->_getDepositTypeIdsByPurchasableId();

        // Grab the current qty of each deposit line before we touch anything.
        // These are the same objects we mutate below, so if we don't take a
        // plain-scalar copy now, the "did the qty change?" check later always
        // comes back equal.
        $originalQtyByDepositTypeId = [];
        foreach ($depositItems as $depositItem) {
            $depositTypeId = $typeIdsByPurchasableId[(int)$depositItem->purchasableId] ?? 0;
            if ($depositTypeId) {
                $originalQtyByDepositTypeId[$depositTypeId] ??= $depositItem->qty;
            }
        }

        // Match the deposit lines we already have to what's expected, fixing
        // the qty and options as we go. A deposit line with no matching product,
        // or a second line for a type that's already matched, is left out on
        // purpose: that's how orphans and duplicates get dropped.
        $reconciledDeposits = [];
        $optionsChanged = false;
        foreach ($depositItems as $depositItem) {
            $depositTypeId = $typeIdsByPurchasableId[(int)$depositItem->purchasableId] ?? 0;
            if (!$depositTypeId || !isset($expectedDeposits[$depositTypeId])) {
                continue;
            }

            $depositItem->qty = $expectedDeposits[$depositTypeId]['qty'];

            $options = $this->_depositOptions($depositTypeId);
            if ($depositItem->getOptions() !== $options) {
                $depositItem->setOptions($options);
                $optionsChanged = true;
            }

            $reconciledDeposits[$depositTypeId] = $depositItem;
            unset($expectedDeposits[$depositTypeId]);
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
        if ($optionsChanged || count($newItems) !== count($allItems)) {
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
     * Returns every deposit type's ID, keyed by the ID of the purchasable that
     * backs it.
     *
     * @return array<int, int> Deposit type ID by purchasable ID.
     * @throws InvalidConfigException If the deposit types service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _getDepositTypeIdsByPurchasableId(): array
    {
        $ids = [];
        foreach (ContainerDeposits::getInstance()->getDepositTypes()->getAllDepositTypes() as $depositType) {
            if ($depositType->purchasableId && $depositType->id) {
                $ids[$depositType->purchasableId] = $depositType->id;
            }
        }

        return $ids;
    }

    /**
     * Returns the options a deposit line of the given type carries.
     *
     * @param int $depositTypeId The deposit type ID.
     * @return array{_deposit: true, _depositTypeId: int} The line item options.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _depositOptions(int $depositTypeId): array
    {
        return [
            '_deposit' => true,
            '_depositTypeId' => $depositTypeId,
        ];
    }

    /**
     * Returns the elements whose field layouts can carry a purchasable's
     * deposit fields, in the order they're checked: the purchasable, then its
     * product when it's a variant.
     *
     * @param ElementInterface $purchasable The purchasable element.
     * @return ElementInterface[] The elements to check.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _getFieldSources(ElementInterface $purchasable): array
    {
        $sources = [$purchasable];

        if ($purchasable instanceof Variant) {
            $owner = $purchasable->getOwner();
            if ($owner !== null) {
                $sources[] = $owner;
            }
        }

        return $sources;
    }

    /**
     * Returns the first custom field of the given class on an element's field
     * layout, or null.
     *
     * @param ElementInterface $element The element to inspect.
     * @param class-string<FieldInterface> $fieldClass The field class to look for.
     * @return FieldInterface|null The field, or null.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _findField(ElementInterface $element, string $fieldClass): ?FieldInterface
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof $fieldClass) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Returns the purchasables that can carry a deposit for one unit of a line
     * item, each with how many of it one unit holds: the line's own
     * purchasable once, plus whatever listeners to
     * EVENT_DEFINE_LINE_ITEM_CONTENTS say is packed inside it. Entries that
     * aren't elements, or have no positive quantity, are skipped.
     *
     * @param LineItem $lineItem The product line item.
     * @return array<int, array{0: ElementInterface, 1: int}> Purchasable and count per unit of the line.
     * @throws InvalidConfigException If the line's purchasable can't be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _getDepositablesForLineItem(LineItem $lineItem): array
    {
        $depositables = [];

        if ($lineItem->type === LineItemType::Purchasable) {
            $purchasable = $lineItem->getPurchasable();
            if ($purchasable instanceof ElementInterface) {
                $depositables[] = [$purchasable, 1];
            }
        }

        if (!$this->hasEventHandlers(self::EVENT_DEFINE_LINE_ITEM_CONTENTS)) {
            return $depositables;
        }

        $event = new DefineLineItemContentsEvent(['lineItem' => $lineItem]);
        $this->trigger(self::EVENT_DEFINE_LINE_ITEM_CONTENTS, $event);

        foreach ($event->contents as $content) {
            $contained = $content['purchasable'] ?? null;
            $qty = (int)($content['qty'] ?? 0);
            if ($contained instanceof ElementInterface && $qty > 0) {
                $depositables[] = [$contained, $qty];
            }
        }

        return $depositables;
    }

    /**
     * Resolves the deposit type assigned to a purchasable, reusing a per-sync
     * cache keyed by purchasable ID.
     *
     * @param ElementInterface $purchasable The purchasable to inspect.
     * @param array<int, DepositType|null> $cache Per-sync deposit-type cache, keyed by purchasable ID.
     * @return DepositType|null The assigned deposit type, or null.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    private function _getDepositTypeCached(ElementInterface $purchasable, array &$cache): ?DepositType
    {
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
     * Resolves the container count for a purchasable, reusing a per-sync cache
     * keyed by purchasable ID.
     *
     * @param ElementInterface $purchasable The purchasable to inspect.
     * @param array<int, int> $cache Per-sync container-count cache, keyed by purchasable ID.
     * @return int The container count per unit.
     * @throws InvalidFieldException
     * @since 1.0.0
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    private function _getContainerCountCached(ElementInterface $purchasable, array &$cache): int
    {
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
     * A deposit purchasable that can't be resolved is logged and skipped
     * rather than failing the whole order save.
     *
     * @param Order $order The order the line item belongs to.
     * @param DepositType $depositType The deposit type to create a line item for.
     * @param int $qty The deposit quantity.
     * @return LineItem|null The created line item, or null if it can't be created.
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @since 1.0.0
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    private function _createDepositLineItem(Order $order, DepositType $depositType, int $qty): ?LineItem
    {
        if (!$depositType->purchasableId || !$depositType->id) {
            return null;
        }

        try {
            return Commerce::getInstance()->getLineItems()->create($order, [
                'purchasableId' => $depositType->purchasableId,
                'options' => $this->_depositOptions($depositType->id),
                'qty' => $qty,
            ]);
        } catch (InvalidArgumentException $e) {
            Craft::error(sprintf(
                'Couldn\'t add the "%s" deposit to order %s: %s',
                $depositType->handle,
                $order->id ?? 'new',
                $e->getMessage(),
            ), 'container-deposits');

            return null;
        }
    }
}
