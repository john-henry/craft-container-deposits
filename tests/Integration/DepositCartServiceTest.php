<?php

use craft\commerce\models\LineItem;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\models\DepositType;

/**
 * Helper: build an in-memory deposit line item exactly as the cart service does.
 * We don't use a real purchasable here because the cart sync logic uses the
 * deposit purchasable id (set via makeDepositType in Pest.php).
 */
function buildDepositLineItem(int $depositTypeId, int $purchasableId, int $qty, float $price): LineItem
{
    $li = new LineItem();
    $li->purchasableId = $purchasableId;
    $li->qty = $qty;
    $li->price = $price;
    $li->setOptions(['_deposit' => true, '_depositTypeId' => $depositTypeId]);
    return $li;
}

/**
 * A stand-in "product" purchasable that reports a fixed DepositType from its
 * (fake) field layout, without needing a real Commerce product/field layout
 * round-trip. Used to exercise DepositCartService::_doSync()'s regular-item
 * resolution path (_getDepositTypeForLineItem / getDepositTypeForPurchasable).
 */
class FakeProductPurchasable extends DepositPurchasable
{
    public ?DepositType $fakeDepositType = null;

    public function getFieldLayout(): ?\craft\models\FieldLayout
    {
        $layout = new \craft\models\FieldLayout();
        $field = new \johnhenry\containerdeposits\fields\DepositTypeField(['handle' => 'depositType']);
        $tab = new \craft\models\FieldLayoutTab();
        $tab->setLayout($layout);
        $tab->setElements([
            (new \craft\fieldlayoutelements\CustomField($field)),
        ]);
        $layout->setTabs([$tab]);
        return $layout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $fieldHandle === 'depositType' ? $this->fakeDepositType : null;
    }
}

/**
 * Builds a regular product-style line item that resolves to the given deposit
 * type via a fake field layout, without touching the DB.
 */
function buildProductLineItemWithDeposit(DepositType $depositType, int $qty, float $price): LineItem
{
    $purchasable = new FakeProductPurchasable();
    $purchasable->fakeDepositType = $depositType;

    $li = new LineItem();
    $li->qty = $qty;
    $li->price = $price;
    $li->setPurchasable($purchasable);

    return $li;
}

it('removes orphaned deposit line items when no parent items have deposits', function () {
    $type = makeDepositType('Can Deposit', 'canDeposit', 0.15);
    $order = makeCart();

    // Manually add an orphan deposit line item (no matching parent).
    $orphan = buildDepositLineItem($type->id, $type->purchasableId, 5, 0.15);
    $order->setLineItems([$orphan]);

    ContainerDeposits::getInstance()->depositCart->syncDepositLineItems($order);

    expect($order->getLineItems())->toHaveCount(0);
});

it('creates and syncs a zero-amount deposit line item rather than stripping it', function () {
    $type = makeDepositType('Free Return', 'freeReturnSync', 0.0);
    $order = makeCart();

    $product = buildProductLineItemWithDeposit($type, 3, 12.00);
    $order->setLineItems([$product]);

    ContainerDeposits::getInstance()->depositCart->syncDepositLineItems($order);

    $items = $order->getLineItems();
    expect($items)->toHaveCount(2);

    $deposits = ContainerDeposits::getInstance()->depositCart->getDepositLineItems($order);
    expect($deposits)->toHaveCount(1);
    expect($deposits[0]->qty)->toBe(3);
    expect($deposits[0]->options['_depositTypeId'] ?? null)->toBe($type->id);
});

it('detects a deposit quantity change when the product line item count stays the same', function () {
    $type = makeDepositType('Can Deposit', 'canDepositQtyChange', 0.15);
    $order = makeCart();

    // Seed the order with one product line item (qty 2) and its matching
    // deposit line item already reconciled at qty 2.
    $product = buildProductLineItemWithDeposit($type, 2, 5.00);
    $existingDeposit = buildDepositLineItem($type->id, $type->purchasableId, 2, 0.15);
    $order->setLineItems([$product, $existingDeposit]);

    // Now bump the product's quantity without changing the item count.
    $product->qty = 5;
    $order->setLineItems([$product, $existingDeposit]);

    ContainerDeposits::getInstance()->depositCart->syncDepositLineItems($order);

    $deposits = ContainerDeposits::getInstance()->depositCart->getDepositLineItems($order);
    expect($deposits)->toHaveCount(1);
    expect($deposits[0]->qty)->toBe(5);
});

it('skips completed orders entirely', function () {
    $type = makeDepositType();
    $order = makeCart();
    $order->isCompleted = true;

    $orphan = buildDepositLineItem($type->id, $type->purchasableId, 99, 0.15);
    $order->setLineItems([$orphan]);

    ContainerDeposits::getInstance()->depositCart->syncDepositLineItems($order);

    // Completed orders are untouched: the orphan deposit remains.
    expect($order->getLineItems())->toHaveCount(1);
});

