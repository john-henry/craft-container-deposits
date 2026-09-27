<?php

/**
 * Covers the "containers per unit" multiplier in the cart sync and the
 * product-display helpers on the Twig variable (containersFor, typeFor,
 * unitDepositFor, displayFor). These are the bits a store uses to show
 * "+ 90c Deposit" on a product and to charge one deposit per container in a
 * multipack.
 */

use craft\commerce\models\LineItem;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\models\DepositType;
use johnhenry\containerdeposits\variables\ContainerDepositsVariable;

/**
 * A stand-in product purchasable that reports both a deposit type and a
 * containers-per-unit count through a fake field layout, so we can exercise the
 * multiplier and the display helpers without a real product and field layout in
 * the DB.
 */
class FakeCountedProductPurchasable extends DepositPurchasable
{
    public ?DepositType $fakeDepositType = null;
    public int $fakeContainers = 1;

    public function getFieldLayout(): ?\craft\models\FieldLayout
    {
        $layout = new \craft\models\FieldLayout();
        $tab = new \craft\models\FieldLayoutTab();
        $tab->setLayout($layout);
        $tab->setElements([
            new \craft\fieldlayoutelements\CustomField(
                new \johnhenry\containerdeposits\fields\DepositTypeField(['handle' => 'depositType'])
            ),
            new \craft\fieldlayoutelements\CustomField(
                new \johnhenry\containerdeposits\fields\ContainerCountField(['handle' => 'containersPerUnit'])
            ),
        ]);
        $layout->setTabs([$tab]);
        return $layout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return match ($fieldHandle) {
            'depositType' => $this->fakeDepositType,
            'containersPerUnit' => $this->fakeContainers,
            default => null,
        };
    }
}

/**
 * Builds a product-style line item that resolves to the given deposit type and
 * container count via a fake field layout, no DB touched.
 */
function countedProductLineItem(DepositType $type, int $qty, int $containers): LineItem
{
    $purchasable = new FakeCountedProductPurchasable();
    $purchasable->fakeDepositType = $type;
    $purchasable->fakeContainers = $containers;

    $li = new LineItem();
    $li->qty = $qty;
    $li->price = 10.00;
    $li->setPurchasable($purchasable);
    return $li;
}

it('multiplies the deposit qty by the containers per unit (a tray of cans)', function () {
    $type = makeDepositType('Can Deposit', 'canDepositTray', 0.15);
    $order = makeCart();

    // 2 trays × 24 cans each = 48 deposits.
    $order->setLineItems([countedProductLineItem($type, 2, 24)]);

    ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

    $deposits = ContainerDeposits::getInstance()->getDepositCart()->getDepositLineItems($order);
    expect($deposits)->toHaveCount(1);
    expect($deposits[0]->qty)->toBe(48);
});

it('rolls several multipacks of the same tier into one deposit line', function () {
    $type = makeDepositType('Can Deposit', 'canDepositRollup', 0.15);
    $order = makeCart();

    $order->setLineItems([
        countedProductLineItem($type, 1, 6),   // one six-pack
        countedProductLineItem($type, 2, 6),   // two more six-packs
    ]);

    ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

    $deposits = ContainerDeposits::getInstance()->getDepositCart()->getDepositLineItems($order);
    expect($deposits)->toHaveCount(1);
    expect($deposits[0]->qty)->toBe(18); // (1 + 2) × 6
});

describe('product-display Twig helpers', function () {
    beforeEach(function () {
        $this->variable = new ContainerDepositsVariable();
    });

    it('containersFor() reads the containers-per-unit off the purchasable', function () {
        $type = makeDepositType('Can Deposit', 'canDepositCount', 0.15);
        $purchasable = new FakeCountedProductPurchasable();
        $purchasable->fakeDepositType = $type;
        $purchasable->fakeContainers = 6;

        expect($this->variable->containersFor($purchasable))->toBe(6);
    });

    it('typeFor() returns the assigned deposit type', function () {
        $type = makeDepositType('Can Deposit', 'canDepositTypeFor', 0.15);
        $purchasable = new FakeCountedProductPurchasable();
        $purchasable->fakeDepositType = $type;

        expect($this->variable->typeFor($purchasable)?->handle)->toBe('canDepositTypeFor');
    });

    it('unitDepositFor() is the deposit amount times the container count', function () {
        $type = makeDepositType('Can Deposit', 'canDepositUnit', 0.15);
        $purchasable = new FakeCountedProductPurchasable();
        $purchasable->fakeDepositType = $type;
        $purchasable->fakeContainers = 6;

        // 0.15 × 6 = 0.90
        expect($this->variable->unitDepositFor($purchasable))->toEqualWithDelta(0.90, 0.001);
    });

    it('displayFor() renders the Re-turn "+ Xc Deposit" label', function () {
        $type = makeDepositType('Can Deposit', 'canDepositDisplay', 0.15);
        $purchasable = new FakeCountedProductPurchasable();
        $purchasable->fakeDepositType = $type;
        $purchasable->fakeContainers = 6;

        // 0.15 × 6 = 0.90 → "90c"
        expect($this->variable->displayFor($purchasable))->toBe('+ 90c Deposit');
    });

    it('displayFor() returns an empty string when the product has no deposit', function () {
        $purchasable = new FakeCountedProductPurchasable(); // no deposit type assigned

        expect($this->variable->displayFor($purchasable))->toBe('');
    });
});
