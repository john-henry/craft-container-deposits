<?php

/**
 * Coverage for the read-only helper methods on DepositCartService that
 * power the Twig variable: getDepositLineItems, getProductLineItems,
 * getDepositTotal, getProductSubtotal.
 */

use craft\commerce\models\LineItem;
use johnhenry\containerdeposits\ContainerDeposits;

function depositLineItem(int $depositTypeId, int $purchasableId, int $qty, float $price): LineItem
{
    $li = new LineItem();
    $li->purchasableId = $purchasableId;
    $li->qty = $qty;
    $li->price = $price;
    $li->setOptions(['_deposit' => true, '_depositTypeId' => $depositTypeId]);
    return $li;
}

function plainLineItem(int $qty, float $price): LineItem
{
    $li = new LineItem();
    $li->qty = $qty;
    $li->price = $price;
    return $li;
}

it('separates deposit and product line items', function () {
    $type = makeDepositType('Small', 'small', 0.15);
    $order = makeCart();
    $order->setLineItems([
        plainLineItem(2, 5.00),
        depositLineItem($type->id, $type->purchasableId, 2, 0.15),
        plainLineItem(1, 12.00),
        depositLineItem($type->id, $type->purchasableId, 1, 0.15),
    ]);

    $service = ContainerDeposits::getInstance()->getDepositCart();
    expect($service->getProductLineItems($order))->toHaveCount(2);
    expect($service->getDepositLineItems($order))->toHaveCount(2);
});

it('sums deposit totals as price × qty across all deposit lines', function () {
    $small = makeDepositType('Small', 'small', 0.15);
    $large = makeDepositType('Large', 'large', 0.25);
    $order = makeCart();
    $order->setLineItems([
        depositLineItem($small->id, $small->purchasableId, 6, 0.15),  // €0.90
        depositLineItem($large->id, $large->purchasableId, 4, 0.25),  // €1.00
    ]);

    expect(ContainerDeposits::getInstance()->getDepositCart()->getDepositTotal($order))
        ->toEqualWithDelta(1.90, 0.001);
});

it('returns zero deposit total for orders with no deposits', function () {
    $order = makeCart();
    $order->setLineItems([plainLineItem(2, 5.00)]);

    expect(ContainerDeposits::getInstance()->getDepositCart()->getDepositTotal($order))
        ->toEqual(0.0);
});

it('getProductSubtotal() sums product line item subtotals, excluding deposits', function () {
    $type = makeDepositType('Small', 'small', 0.15);
    $order = makeCart();

    $order->setLineItems([
        plainLineItem(2, 5.00),   // subtotal 10.00
        plainLineItem(1, 12.00),  // subtotal 12.00
        depositLineItem($type->id, $type->purchasableId, 3, 0.15),
    ]);

    $subtotal = ContainerDeposits::getInstance()->getDepositCart()->getProductSubtotal($order);
    expect($subtotal)->toEqualWithDelta(22.00, 0.001);
});
