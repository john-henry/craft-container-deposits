<?php

use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\models\DepositType;

it('saves a new deposit type and creates a matching purchasable element', function () {
    $type = makeDepositType('Can Deposit', 'canDeposit', 0.15);

    expect($type->id)->not->toBeNull();
    expect($type->purchasableId)->not->toBeNull();

    $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);
    expect($purchasable)->toBeInstanceOf(DepositPurchasable::class);
    expect($purchasable->depositTypeId)->toBe($type->id);
});

it('updates the deposit type and reuses the existing purchasable', function () {
    $type = makeDepositType('Can Deposit', 'canDeposit', 0.15);
    $originalPurchasableId = $type->purchasableId;

    $type->amount = 0.20;
    ContainerDeposits::getInstance()->getDepositTypes()->saveDepositType($type);

    expect($type->purchasableId)->toBe($originalPurchasableId);

    $reloaded = ContainerDeposits::getInstance()->getDepositTypes()->getDepositTypeById($type->id);
    expect($reloaded->amount)->toEqual(0.20);
});

it('exposes deposit amount via the purchasable price', function () {
    $type = makeDepositType('Large Container', 'largeContainer', 0.25);
    /** @var DepositPurchasable $purchasable */
    $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);

    expect($purchasable->getPrice())->toEqual(0.25);
    expect($purchasable->getSku())->toBe('deposit-largeContainer');
    expect($purchasable->getDescription())->toBe('Large Container');
});

it('marks deposit purchasables as non-promotable with free shipping', function () {
    $type = makeDepositType();
    /** @var DepositPurchasable $purchasable */
    $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);

    expect($purchasable->getIsPromotable())->toBeFalse();
    expect($purchasable->hasFreeShipping())->toBeTrue();
});

it('uses the containerDeposit tax category', function () {
    $type = makeDepositType();
    /** @var DepositPurchasable $purchasable */
    $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);

    $expected = \craft\commerce\Plugin::getInstance()
        ->getTaxCategories()
        ->getTaxCategoryByHandle('containerDeposit');

    expect($expected)->not->toBeNull();
    expect($purchasable->getTaxCategoryId())->toBe($expected->id);
});

it('finds deposit types by handle', function () {
    makeDepositType('Container Deposits', 'containerDeposit', 0.25);

    $found = ContainerDeposits::getInstance()->getDepositTypes()->getDepositTypeByHandle('containerDeposit');
    expect($found)->toBeInstanceOf(DepositType::class);
    expect($found->amount)->toEqual(0.25);
});

it('deletes the deposit type and its purchasable element', function () {
    $type = makeDepositType();
    $purchasableId = $type->purchasableId;

    ContainerDeposits::getInstance()->getDepositTypes()->deleteDepositTypeById($type->id);

    expect(ContainerDeposits::getInstance()->getDepositTypes()->getDepositTypeById($type->id))->toBeNull();
    expect(Craft::$app->getElements()->getElementById($purchasableId))->toBeNull();
});

it('keeps a zero-amount deposit purchasable available (not stripped from carts)', function () {
    $type = makeDepositType('Promotional Free Return', 'freeReturnAvail', 0.0);
    /** @var DepositPurchasable $purchasable */
    $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);

    expect($purchasable->getIsAvailable())->toBeTrue();
});

it('reports a deposit purchasable unavailable only when its deposit type is gone', function () {
    $type = makeDepositType('Temp Deposit', 'tempDepositAvail', 0.15);
    /** @var DepositPurchasable $purchasable */
    $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);

    expect($purchasable->getIsAvailable())->toBeTrue();

    ContainerDeposits::getInstance()->getDepositTypes()->deleteDepositTypeById($type->id);

    // A fresh instance pointing at the deleted type, with nothing memoized
    $orphan = new DepositPurchasable(['depositTypeId' => $type->id]);

    expect(Craft::$app->getElements()->getElementById($purchasable->id, DepositPurchasable::class))->toBeNull();
    expect($orphan->getIsAvailable())->toBeFalse();
});

it('rejects a duplicate handle with a validation error instead of throwing', function () {
    makeDepositType('Can Deposit', 'dupHandle', 0.15);

    $duplicate = new DepositType(['name' => 'Another Deposit', 'handle' => 'dupHandle', 'amount' => 0.25]);
    $saved = ContainerDeposits::getInstance()->getDepositTypes()->saveDepositType($duplicate);

    expect($saved)->toBeFalse();
    expect($duplicate->getErrors('handle'))->not->toBeEmpty();
});

it('never persists a deposit type record with a null purchasableId', function () {
    $type = makeDepositType('Atomic Deposit', 'atomicDeposit', 0.15);

    // A successful save must leave a linked purchasable: the transaction
    // guarantees the record and its purchasable are committed together.
    $record = \johnhenry\containerdeposits\records\DepositTypeRecord::findOne($type->id);
    expect($record)->not->toBeNull();
    expect($record->purchasableId)->not->toBeNull();
});

it('rolls back the record save when the purchasable sync fails', function () {
    // Subclass the service so _syncPurchasable() always fails, simulating an
    // element-save failure after the record has already been written inside the
    // transaction. The real transactional saveDepositType() runs unchanged.
    $failing = new class extends \johnhenry\containerdeposits\services\DepositTypeService {
        protected function _syncPurchasable(DepositType $depositType): bool
        {
            return false;
        }
    };

    $model = new DepositType(['name' => 'Rollback Deposit', 'handle' => 'rollbackDeposit', 'amount' => 0.15]);
    $saved = $failing->saveDepositType($model);

    expect($saved)->toBeFalse();
    expect($model->getErrors('purchasableId'))->not->toBeEmpty();

    // No orphaned record should survive the rolled-back transaction.
    $leftover = \johnhenry\containerdeposits\records\DepositTypeRecord::findOne(['handle' => 'rollbackDeposit']);
    expect($leftover)->toBeNull();
});

it('allows re-saving the same deposit type without tripping its own uniqueness check', function () {
    $type = makeDepositType('Can Deposit', 'selfSaveHandle', 0.15);

    $type->amount = 0.30;
    $saved = ContainerDeposits::getInstance()->getDepositTypes()->saveDepositType($type);

    expect($saved)->toBeTrue();
    expect($type->getErrors('handle'))->toBeEmpty();
});

it('finds the deposit purchasable from every site, so a cart on any site can add it', function () {
    $type = makeDepositType();

    foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
        $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class, $site->id);
        expect($purchasable)->toBeInstanceOf(DepositPurchasable::class);
    }
});

it('accepts camelCase handles', function () {
    $type = new DepositType(['name' => 'Bottle Deposit', 'handle' => 'bottleDepositCamelCase', 'amount' => 0.15]);

    expect($type->validate(['handle']))->toBeTrue();
});

it('accepts the Re-turn standard tiers', function () {
    $small = new DepositType(['name' => 'Small Container', 'handle' => 'smallContainerTier', 'amount' => 0.15]);
    $large = new DepositType(['name' => 'Large Container', 'handle' => 'largeContainerTier', 'amount' => 0.25]);

    expect($small->validate())->toBeTrue();
    expect($large->validate())->toBeTrue();
});
