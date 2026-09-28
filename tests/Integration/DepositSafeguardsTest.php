<?php

/**
 * What keeps a deposit charged at its full amount.
 *
 * A deposit line's type comes from the purchasable it points at, never from
 * options a customer can post; deposit purchasables can't be added to a cart
 * by hand; custom line items and badly formed event contents can't break the
 * sync; the deposit type field works from the product layout too; deposits
 * have no promotional price, don't ship and don't count toward discount
 * minimums; and a deposit type that's still assigned can't be deleted.
 */

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\LineItemType;
use craft\commerce\models\Discount;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\events\DefineLineItemContentsEvent;
use johnhenry\containerdeposits\fields\DepositTypeField;
use johnhenry\containerdeposits\models\DepositType;
use johnhenry\containerdeposits\services\DepositCartService;
use johnhenry\containerdeposits\services\DepositTypeService;
use johnhenry\containerdeposits\variables\ContainerDepositsVariable;
use yii\base\Event;

/**
 * A field layout holding a deposit type field, or no fields at all.
 */
function safeguardLayout(bool $withDepositField): FieldLayout
{
    $layout = new FieldLayout();
    $tab = new FieldLayoutTab();
    $tab->setLayout($layout);
    $tab->setElements($withDepositField ? [new CustomField(new DepositTypeField(['handle' => 'depositType']))] : []);
    $layout->setTabs([$tab]);

    return $layout;
}

/**
 * A purchasable reporting a fixed deposit type, standing in for a product
 * variant without a Commerce product round-trip.
 */
class SafeguardPurchasable extends DepositPurchasable
{
    public ?DepositType $fakeDepositType = null;

    public function getFieldLayout(): ?FieldLayout
    {
        return safeguardLayout(true);
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $fieldHandle === 'depositType' ? $this->fakeDepositType : null;
    }
}

/**
 * A variant whose layout may or may not hold the deposit type field.
 */
class SafeguardVariant extends Variant
{
    public bool $hasDepositField = false;
    public ?DepositType $fakeDepositType = null;

    public function getFieldLayout(): ?FieldLayout
    {
        return safeguardLayout($this->hasDepositField);
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $fieldHandle === 'depositType' ? $this->fakeDepositType : null;
    }
}

/**
 * A product whose layout holds the deposit type field.
 */
class SafeguardProduct extends Product
{
    public ?DepositType $fakeDepositType = null;

    public function getFieldLayout(): ?FieldLayout
    {
        return safeguardLayout(true);
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $fieldHandle === 'depositType' ? $this->fakeDepositType : null;
    }
}

function safeguardProductLine(DepositType $type, int $qty): LineItem
{
    $purchasable = new SafeguardPurchasable();
    $purchasable->fakeDepositType = $type;

    $lineItem = new LineItem();
    $lineItem->qty = $qty;
    $lineItem->price = 2.0;
    $lineItem->setPurchasable($purchasable);

    return $lineItem;
}

function safeguardDepositLine(DepositType $pointsAt, int $postedTypeId, int $qty): LineItem
{
    $lineItem = new LineItem();
    $lineItem->purchasableId = $pointsAt->purchasableId;
    $lineItem->qty = $qty;
    $lineItem->price = $pointsAt->amount;
    $lineItem->setOptions(['_deposit' => true, '_depositTypeId' => $postedTypeId]);

    return $lineItem;
}

describe('A deposit line’s type', function () {
    it('comes from its purchasable, not a posted _depositTypeId', function () {
        $can = makeDepositType('Can Deposit', 'canForged', 0.15);
        $large = makeDepositType('Large Deposit', 'largeForged', 0.25);
        $order = makeCart();

        // 24 large containers, their genuine deposit line, and a posted line on
        // the cheap purchasable claiming to be the large type, placed first the
        // way Commerce adds new lines
        $forged = safeguardDepositLine($can, $large->id, 1);
        $genuine = safeguardDepositLine($large, $large->id, 24);
        $order->setLineItems([$forged, safeguardProductLine($large, 24), $genuine]);

        ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

        $deposits = ContainerDeposits::getInstance()->getDepositCart()->getDepositLineItems($order);
        expect($deposits)->toHaveCount(1);
        expect((int)$deposits[0]->purchasableId)->toBe($large->purchasableId);
        expect($deposits[0]->qty)->toBe(24);
    });

    it('has swapped options put back to match its purchasable', function () {
        $can = makeDepositType('Can Deposit', 'canSwapped', 0.15);
        $large = makeDepositType('Large Deposit', 'largeSwapped', 0.25);
        $order = makeCart();

        $canLine = safeguardDepositLine($can, $large->id, 6);
        $largeLine = safeguardDepositLine($large, $can->id, 2);
        $order->setLineItems([safeguardProductLine($can, 6), safeguardProductLine($large, 2), $canLine, $largeLine]);

        ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

        expect($canLine->qty)->toBe(6);
        expect($canLine->getOptions()['_depositTypeId'])->toBe($can->id);
        expect($largeLine->qty)->toBe(2);
        expect($largeLine->getOptions()['_depositTypeId'])->toBe($large->id);
    });
});

describe('A deposit purchasable', function () {
    it('can’t be added to a cart directly', function () {
        $type = makeDepositType('Can Deposit', 'canDirect', 0.15);
        $order = makeCart();

        $lineItem = Commerce::getInstance()->getLineItems()->create($order, ['purchasableId' => $type->purchasableId, 'qty' => 5]);
        $order->addLineItem($lineItem);

        expect($order->getLineItems())->toBe([]);
    });

    it('never has a promotional price and doesn’t ship', function () {
        $type = makeDepositType('Can Deposit', 'canPromo', 0.15);
        /** @var DepositPurchasable $purchasable */
        $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);
        $purchasable->setPromotionalPrice(0.0);

        expect($purchasable->getPromotionalPrice())->toBeNull();
        expect($purchasable->getSalePrice())->toBe(0.15);
        expect($purchasable->getIsShippable())->toBeFalse();

        $lineItem = new LineItem();
        $lineItem->setPromotionalPrice(0.0);
        $purchasable->populateLineItem($lineItem);

        expect($lineItem->getPromotionalPrice())->toBeNull();
        expect($lineItem->getSalePrice())->toBe(0.15);
    });
});

describe('The sync', function () {
    it('leaves custom line items alone and still charges the products’ deposits', function () {
        $type = makeDepositType('Can Deposit', 'canCustom', 0.15);
        $order = makeCart();

        $custom = new LineItem();
        $custom->type = LineItemType::Custom;
        $custom->qty = 1;
        $custom->price = 3.0;
        $custom->setOptions(['_deposit' => true, '_depositTypeId' => $type->id]);

        $order->setLineItems([$custom, safeguardProductLine($type, 4)]);
        ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

        $deposits = ContainerDeposits::getInstance()->getDepositCart()->getDepositLineItems($order);
        expect($deposits)->toHaveCount(1);
        expect($deposits[0]->qty)->toBe(4);
        expect($order->getLineItems())->toContain($custom);
    });

    it('skips contents a listener sends in the wrong shape', function () {
        $type = makeDepositType('Can Deposit', 'canBadContents', 0.15);
        $line = safeguardProductLine($type, 1);

        $handler = static function (DefineLineItemContentsEvent $event) use ($line): void {
            if ($event->lineItem === $line) {
                $event->contents[] = ['purchasable' => 'not an element', 'qty' => 3];
                $event->contents[] = ['qty' => 3];
                $event->contents[] = ['purchasable' => $line->getPurchasable()];
            }
        };
        Event::on(DepositCartService::class, DepositCartService::EVENT_DEFINE_LINE_ITEM_CONTENTS, $handler);

        try {
            $order = makeCart();
            $order->setLineItems([$line]);
            ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);
        } finally {
            Event::off(DepositCartService::class, DepositCartService::EVENT_DEFINE_LINE_ITEM_CONTENTS, $handler);
        }

        $deposits = ContainerDeposits::getInstance()->getDepositCart()->getDepositLineItems($order);
        expect($deposits)->toHaveCount(1);
        expect($deposits[0]->qty)->toBe(1);
    });
});

describe('The deposit type field', function () {
    it('is read from the product when the variant layout hasn’t got it', function () {
        $type = makeDepositType('Can Deposit', 'canProductLayout', 0.15);
        $product = new SafeguardProduct();
        $product->typeId = (int)(new \craft\db\Query())->select('id')->from('{{%commerce_producttypes}}')->scalar();
        $product->fakeDepositType = $type;
        $variant = new SafeguardVariant();
        $variant->setOwner($product);

        expect(ContainerDeposits::getInstance()->getDepositCart()->getDepositTypeForPurchasable($variant)?->id)->toBe($type->id);
    });

    it('is read from the variant when its layout has it, even left empty', function () {
        $type = makeDepositType('Can Deposit', 'canVariantLayout', 0.15);
        $product = new SafeguardProduct();
        $product->typeId = (int)(new \craft\db\Query())->select('id')->from('{{%commerce_producttypes}}')->scalar();
        $product->fakeDepositType = $type;
        $variant = new SafeguardVariant();
        $variant->hasDepositField = true;
        $variant->setOwner($product);

        expect(ContainerDeposits::getInstance()->getDepositCart()->getDepositTypeForPurchasable($variant))->toBeNull();
    });
});

describe('Discount minimums', function () {
    it('are checked on the products, leaving the deposits out', function () {
        $type = makeDepositType('Can Deposit', 'canThreshold', 0.15);
        $discount = new Discount(['allPurchasables' => true, 'allCategories' => true, 'purchaseQty' => 12]);

        $order = makeCart();
        $order->setLineItems([safeguardProductLine($type, 6)]);
        ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

        expect($order->getTotalQty())->toBe(12);
        expect(ContainerDeposits::getInstance()->getDepositCart()->meetsDiscountThresholds($order, $discount))->toBeFalse();

        $order->setLineItems([safeguardProductLine($type, 12)]);
        ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

        expect(ContainerDeposits::getInstance()->getDepositCart()->meetsDiscountThresholds($order, $discount))->toBeTrue();
    });
});

describe('Deleting a deposit type', function () {
    it('is refused while the type is still assigned', function () {
        $type = makeDepositType('Can Deposit', 'canInUse', 0.15);

        $service = new class() extends DepositTypeService {
            public function getUsageCount(int $id): int
            {
                return 3;
            }
        };

        expect($service->deleteDepositTypeById($type->id))->toBeFalse();
        expect(ContainerDeposits::getInstance()->getDepositTypes()->getDepositTypeById($type->id))->not->toBeNull();
    });

    it('counts nothing for a type no product uses', function () {
        $type = makeDepositType('Unused Deposit', 'unusedDeposit', 0.15);

        expect(ContainerDeposits::getInstance()->getDepositTypes()->getUsageCount($type->id))->toBe(0);
    });
});

describe('formatAmount()', function () {
    it('uses cents or pence where the currency is written that way', function () {
        $variable = new ContainerDepositsVariable();

        expect($variable->formatAmount(0.15, 'EUR'))->toBe('15c');
        expect($variable->formatAmount(0.15, 'GBP'))->toBe('15p');
        expect($variable->formatAmount(0.15, 'CHF'))->toContain('0.15');
        expect($variable->minorUnitSuffix('CHF'))->toBeNull();
    });
});
