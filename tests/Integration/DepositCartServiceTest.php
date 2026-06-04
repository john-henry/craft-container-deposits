<?php

use craft\commerce\models\LineItem;
use johnhenry\containerdeposits\ContainerDeposits;

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

it('removes orphaned deposit line items when no parent items have deposits', function () {
    $type = makeDepositType('Can Deposit', 'canDeposit', 0.15);
    $order = makeCart();

    // Manually add an orphan deposit line item (no matching parent).
    $orphan = buildDepositLineItem($type->id, $type->purchasableId, 5, 0.15);
    $order->setLineItems([$orphan]);

    ContainerDeposits::getInstance()->depositCart->syncDepositLineItems($order);

    expect($order->getLineItems())->toHaveCount(0);
});

it('skips completed orders entirely', function () {
    $type = makeDepositType();
    $order = makeCart();
    $order->isCompleted = true;

    $orphan = buildDepositLineItem($type->id, $type->purchasableId, 99, 0.15);
    $order->setLineItems([$orphan]);

    ContainerDeposits::getInstance()->depositCart->syncDepositLineItems($order);

    // Completed orders are untouched — the orphan deposit remains.
    expect($order->getLineItems())->toHaveCount(1);
});

