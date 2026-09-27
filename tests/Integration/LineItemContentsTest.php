<?php

/**
 * Coverage for EVENT_DEFINE_LINE_ITEM_CONTENTS: a line whose own purchasable
 * has no deposit type (a bundle, a gift box) still carries the deposits of the
 * containers another plugin says are packed inside it.
 */

use craft\commerce\models\LineItem;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\events\DefineLineItemContentsEvent;
use johnhenry\containerdeposits\fields\DepositTypeField;
use johnhenry\containerdeposits\models\DepositType;
use johnhenry\containerdeposits\services\DepositCartService;
use yii\base\Event;

/**
 * A purchasable that reports a fixed deposit type (or none) from a fake field
 * layout, without a real Commerce product round-trip.
 */
class FakeContentsPurchasable extends DepositPurchasable
{
    public ?DepositType $fakeDepositType = null;

    public function getFieldLayout(): ?\craft\models\FieldLayout
    {
        $layout = new \craft\models\FieldLayout();
        $tab = new \craft\models\FieldLayoutTab();
        $tab->setLayout($layout);
        $tab->setElements([
            new \craft\fieldlayoutelements\CustomField(new DepositTypeField(['handle' => 'depositType'])),
        ]);
        $layout->setTabs([$tab]);

        return $layout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $fieldHandle === 'depositType' ? $this->fakeDepositType : null;
    }
}

function fakeContentsPurchasable(?DepositType $depositType, int $id): FakeContentsPurchasable
{
    $purchasable = new FakeContentsPurchasable();
    $purchasable->id = $id;
    $purchasable->fakeDepositType = $depositType;

    return $purchasable;
}

function contentsLineItem(FakeContentsPurchasable $purchasable, int $qty): LineItem
{
    $lineItem = new LineItem();
    $lineItem->qty = $qty;
    $lineItem->price = 20.0;
    $lineItem->setPurchasable($purchasable);

    return $lineItem;
}

/**
 * @return array<int, int> Deposit type ID => deposit line qty.
 */
function depositQtysByType(\craft\commerce\elements\Order $order): array
{
    $qtys = [];
    foreach (ContainerDeposits::getInstance()->getDepositCart()->getDepositLineItems($order) as $item) {
        $qtys[(int)$item->options['_depositTypeId']] = $item->qty;
    }
    ksort($qtys);

    return $qtys;
}

/**
 * Has the listener report the given contents for one line item. The handler
 * is kept on the test so afterEach() can take it off again.
 *
 * @param array<int, array{purchasable: FakeContentsPurchasable, qty: int}> $contents
 */
function listenForContents(object $test, LineItem $line, array $contents): void
{
    $test->contentsHandler = static function (DefineLineItemContentsEvent $event) use ($line, $contents): void {
        if ($event->lineItem === $line) {
            array_push($event->contents, ...$contents);
        }
    };

    Event::on(DepositCartService::class, DepositCartService::EVENT_DEFINE_LINE_ITEM_CONTENTS, $test->contentsHandler);
}

afterEach(function () {
    if (isset($this->contentsHandler)) {
        Event::off(DepositCartService::class, DepositCartService::EVENT_DEFINE_LINE_ITEM_CONTENTS, $this->contentsHandler);
    }
});

it('charges the deposits of the containers packed inside a line', function () {
    $can = makeDepositType('Can Deposit', 'canContents', 0.15);
    $large = makeDepositType('Large Container Deposit', 'largeContents', 0.25);

    $bundle = contentsLineItem(fakeContentsPurchasable(null, 900001), 2);
    $cans = fakeContentsPurchasable($can, 900002);
    $flagon = fakeContentsPurchasable($large, 900003);

    listenForContents($this, $bundle, [
        ['purchasable' => $cans, 'qty' => 6],
        ['purchasable' => $flagon, 'qty' => 1],
    ]);

    $order = makeCart();
    $order->setLineItems([$bundle]);
    ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

    // 2 bundles × 6 cans, and 2 bundles × 1 flagon
    expect(depositQtysByType($order))->toBe([$can->id => 12, $large->id => 2]);
});

it('adds the contents to the line’s own deposit rather than replacing it', function () {
    $can = makeDepositType('Can Deposit', 'canOwnAndContents', 0.15);

    $line = contentsLineItem(fakeContentsPurchasable($can, 900011), 1);
    $extra = fakeContentsPurchasable($can, 900012);

    listenForContents($this, $line, [
        ['purchasable' => $extra, 'qty' => 3],
    ]);

    $order = makeCart();
    $order->setLineItems([$line]);
    ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

    expect(depositQtysByType($order))->toBe([$can->id => 4]);
});

it('skips contents with no deposit type or no quantity', function () {
    $can = makeDepositType('Can Deposit', 'canSkipContents', 0.15);

    $line = contentsLineItem(fakeContentsPurchasable(null, 900021), 1);
    $glass = fakeContentsPurchasable(null, 900022);
    $cans = fakeContentsPurchasable($can, 900023);

    listenForContents($this, $line, [
        ['purchasable' => $glass, 'qty' => 4],
        ['purchasable' => $cans, 'qty' => 0],
    ]);

    $order = makeCart();
    $order->setLineItems([$line]);
    ContainerDeposits::getInstance()->getDepositCart()->syncDepositLineItems($order);

    expect(depositQtysByType($order))->toBe([]);
});
