<?php

/**
 * What counts as a deposit line, and who gets to decide.
 *
 * Line item options come off the add-to-cart request, so a deposit line is
 * decided by the purchasable it points at. The `_deposit` option only counts
 * on a completed order, where nothing can be posted.
 */

use johnhenry\containerdeposits\ContainerDeposits;

describe('DepositCartService::isDepositLineItem()', function() {
    it('ignores a _deposit option posted onto an ordinary product', function() {
        $cart = ContainerDeposits::getInstance()->getDepositCart();
        $type = makeDepositType('Posted Option', 'postedOption', 0.15);

        $honest = buildProductLineItemWithDeposit($type, 2, 5.00);
        $honestOrder = makeCart();
        $honestOrder->setLineItems([$honest]);
        $cart->syncDepositLineItems($honestOrder);

        $sneaky = buildProductLineItemWithDeposit($type, 2, 5.00);
        $sneaky->setOptions(array_merge($sneaky->getOptions(), ['_deposit' => true]));
        $sneakyOrder = makeCart();
        $sneakyOrder->setLineItems([$sneaky]);
        $cart->syncDepositLineItems($sneakyOrder);

        expect($sneakyOrder->getLineItems())->toHaveCount(count($honestOrder->getLineItems()))
            ->and($cart->getDepositTotal($sneakyOrder))->toBe($cart->getDepositTotal($honestOrder))
            ->and($cart->getDepositTotal($sneakyOrder))->toBe(0.3);
    });

    it('does not treat a product carrying the option as a deposit line', function() {
        $cart = ContainerDeposits::getInstance()->getDepositCart();
        $type = makeDepositType('Not A Deposit', 'notADeposit', 0.15);

        $product = buildProductLineItemWithDeposit($type, 1, 5.00);
        $product->setOptions(array_merge($product->getOptions(), ['_deposit' => true]));

        expect($cart->isDepositLineItem($product))->toBeFalse();
    });

    it('recognises a real deposit line by the purchasable it points at', function() {
        $cart = ContainerDeposits::getInstance()->getDepositCart();
        $type = makeDepositType('Real Deposit', 'realDeposit', 0.25);

        $deposit = buildDepositLineItem($type->id, $type->purchasableId, 3, 0.25);

        expect($cart->isDepositLineItem($deposit))->toBeTrue();
    });

    it('trusts the option on a completed order whose purchasable is gone', function() {
        $cart = ContainerDeposits::getInstance()->getDepositCart();

        $order = makeCart();
        $order->isCompleted = true;

        // A historical order whose deposit type, and its purchasable, were
        // deleted afterwards. Nothing can be posted to one of those.
        $orphan = buildDepositLineItem(0, 0, 2, 0.15);
        $orphan->purchasableId = null;
        $orphan->setOrder($order);

        expect($cart->isDepositLineItem($orphan))->toBeTrue();
    });

    it('ignores the option on a cart, where the customer can post it', function() {
        $cart = ContainerDeposits::getInstance()->getDepositCart();

        $posed = buildDepositLineItem(0, 0, 2, 0.15);
        $posed->purchasableId = null;
        $posed->setOrder(makeCart());

        expect($cart->isDepositLineItem($posed))->toBeFalse();
    });

    it('never counts a custom line item as a deposit, and reading it doesn\'t throw', function() {
        $cart = ContainerDeposits::getInstance()->getDepositCart();

        $custom = new \craft\commerce\models\LineItem();
        $custom->type = \craft\commerce\enums\LineItemType::Custom;
        $custom->qty = 1;
        $custom->setOptions(['_deposit' => true]);

        expect($cart->isDepositLineItem($custom))->toBeFalse();
    });
});
