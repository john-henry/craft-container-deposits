<?php

/**
 * Integration coverage for the craft.containerDeposits Twig variable.
 * Tests the order-level helpers that the cart/checkout/receipt templates rely on.
 */

use craft\commerce\models\LineItem;
use johnhenry\containerdeposits\variables\ContainerDepositsVariable;

function tvDepositItem(int $depositTypeId, int $purchasableId, int $qty, float $price): LineItem
{
    $li = new LineItem();
    $li->purchasableId = $purchasableId;
    $li->qty = $qty;
    $li->price = $price;
    $li->setOptions(['_deposit' => true, '_depositTypeId' => $depositTypeId]);
    return $li;
}

function tvPlainItem(int $qty, float $price): LineItem
{
    $li = new LineItem();
    $li->qty = $qty;
    $li->price = $price;
    return $li;
}

beforeEach(function () {
    $this->variable = new ContainerDepositsVariable();
});

it('totalFor() returns sum of deposit line items', function () {
    $small = makeDepositType('Small', 'small', 0.15);
    $large = makeDepositType('Large', 'large', 0.25);
    $order = makeCart();
    $order->setLineItems([
        tvDepositItem($small->id, $small->purchasableId, 6, 0.15),
        tvDepositItem($large->id, $large->purchasableId, 4, 0.25),
    ]);

    expect($this->variable->totalFor($order))->toEqualWithDelta(1.90, 0.001);
});

it('productQtyFor() excludes deposit qty from the badge count', function () {
    $type = makeDepositType();
    $order = makeCart();
    $order->setLineItems([
        tvPlainItem(2, 5.00),
        tvPlainItem(3, 5.00),
        tvDepositItem($type->id, $type->purchasableId, 99, 0.15),
    ]);

    expect($this->variable->productQtyFor($order))->toBe(5);
});

it('sortedLineItemsFor() places products first, deposits last', function () {
    $type = makeDepositType();
    $order = makeCart();

    $product1 = tvPlainItem(1, 5.00);
    $deposit  = tvDepositItem($type->id, $type->purchasableId, 1, 0.15);
    $product2 = tvPlainItem(1, 12.00);
    // Intentionally interleaved on the order:
    $order->setLineItems([$deposit, $product1, $product2]);

    $sorted = $this->variable->sortedLineItemsFor($order);

    expect($sorted)->toHaveCount(3);
    expect($sorted[0]->options['_deposit'] ?? false)->toBeFalse();
    expect($sorted[1]->options['_deposit'] ?? false)->toBeFalse();
    expect($sorted[2]->options['_deposit'] ?? false)->toBeTrue();
});

it('isDeposit() identifies deposit line items', function () {
    $type = makeDepositType();
    $deposit = tvDepositItem($type->id, $type->purchasableId, 1, 0.15);
    $product = tvPlainItem(1, 5.00);

    expect($this->variable->isDeposit($deposit))->toBeTrue();
    expect($this->variable->isDeposit($product))->toBeFalse();
});

it('lineItemsFor() returns only deposit line items', function () {
    $type = makeDepositType();
    $order = makeCart();
    $order->setLineItems([
        tvPlainItem(1, 5.00),
        tvDepositItem($type->id, $type->purchasableId, 2, 0.15),
    ]);

    $lines = $this->variable->lineItemsFor($order);
    expect($lines)->toHaveCount(1);
    expect($lines[0]->options['_deposit'] ?? false)->toBeTrue();
});

it('productLineItemsFor() returns only non-deposit line items', function () {
    $type = makeDepositType();
    $order = makeCart();
    $order->setLineItems([
        tvPlainItem(2, 5.00),
        tvPlainItem(1, 8.00),
        tvDepositItem($type->id, $type->purchasableId, 3, 0.15),
    ]);

    $lines = $this->variable->productLineItemsFor($order);
    expect($lines)->toHaveCount(2);
    foreach ($lines as $line) {
        expect(empty($line->options['_deposit']))->toBeTrue();
    }
});

it('productSubtotalFor() sums product subtotals and excludes deposits', function () {
    $type = makeDepositType();
    $order = makeCart();
    $order->setLineItems([
        tvPlainItem(2, 5.00),   // subtotal 10.00
        tvPlainItem(1, 12.00),  // subtotal 12.00
        tvDepositItem($type->id, $type->purchasableId, 99, 0.15),
    ]);

    expect($this->variable->productSubtotalFor($order))->toEqualWithDelta(22.00, 0.001);
});

it('allTypes() includes every configured deposit type', function () {
    makeDepositType('Small', 'small', 0.15);
    makeDepositType('Large', 'large', 0.25);

    $handles = array_map(fn($t) => $t->handle, $this->variable->allTypes());
    expect($handles)->toContain('small');
    expect($handles)->toContain('large');
});
