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
    ContainerDeposits::getInstance()->depositTypes->saveDepositType($type);

    expect($type->purchasableId)->toBe($originalPurchasableId);

    $reloaded = ContainerDeposits::getInstance()->depositTypes->getDepositTypeById($type->id);
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

    $found = ContainerDeposits::getInstance()->depositTypes->getDepositTypeByHandle('containerDeposit');
    expect($found)->toBeInstanceOf(DepositType::class);
    expect($found->amount)->toEqual(0.25);
});

it('deletes the deposit type and its purchasable element', function () {
    $type = makeDepositType();
    $purchasableId = $type->purchasableId;

    ContainerDeposits::getInstance()->depositTypes->deleteDepositTypeById($type->id);

    expect(ContainerDeposits::getInstance()->depositTypes->getDepositTypeById($type->id))->toBeNull();
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

    ContainerDeposits::getInstance()->depositTypes->deleteDepositTypeById($type->id);

    // Re-fetch a fresh instance so nothing is memoized from before the delete.
    /** @var DepositPurchasable|null $reloaded */
    $reloaded = Craft::$app->getElements()->getElementById($purchasable->id, DepositPurchasable::class);

    expect($reloaded === null || $reloaded->getIsAvailable() === false)->toBeTrue();
});

it('rejects a duplicate handle with a validation error instead of throwing', function () {
    makeDepositType('Can Deposit', 'dupHandle', 0.15);

    $duplicate = new DepositType(['name' => 'Another Deposit', 'handle' => 'dupHandle', 'amount' => 0.25]);
    $saved = ContainerDeposits::getInstance()->depositTypes->saveDepositType($duplicate);

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
    $saved = ContainerDeposits::getInstance()->depositTypes->saveDepositType($type);

    expect($saved)->toBeTrue();
    expect($type->getErrors('handle'))->toBeEmpty();
});

it('propagates the deposit purchasable to every enabled site', function () {
    $type = makeDepositType();
    /** @var DepositPurchasable $purchasable */
    $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);

    $supported = $purchasable->getSupportedSites();

    // getSupportedSites() only includes enabled sites; every configured site in
    // the test environment is enabled, so the counts should match.
    $enabledSiteIds = [];
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        if ($site->getEnabled()) {
            $enabledSiteIds[] = $site->id;
        }
    }

    expect($supported)->toHaveCount(count($enabledSiteIds));

    $supportedSiteIds = array_column($supported, 'siteId');
    sort($supportedSiteIds);
    sort($enabledSiteIds);
    expect($supportedSiteIds)->toBe($enabledSiteIds);

    // Every supported site is configured to propagate and be enabled by default
    foreach ($supported as $entry) {
        expect($entry)->toHaveKeys(['siteId', 'propagate', 'enabledByDefault']);
        expect($entry['propagate'])->toBeTrue();
        expect($entry['enabledByDefault'])->toBeTrue();
    }
});
