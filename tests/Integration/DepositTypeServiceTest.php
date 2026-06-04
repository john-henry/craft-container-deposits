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

it('propagates the deposit purchasable to every enabled site', function () {
    $type = makeDepositType();
    /** @var DepositPurchasable $purchasable */
    $purchasable = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);

    $supported = $purchasable->getSupportedSites();
    $allSiteIds = Craft::$app->getSites()->getAllSiteIds();

    expect($supported)->toHaveCount(count($allSiteIds));

    // Every supported site is configured to propagate and be enabled by default
    foreach ($supported as $entry) {
        expect($entry)->toHaveKeys(['siteId', 'propagate', 'enabledByDefault']);
        expect($entry['propagate'])->toBeTrue();
        expect($entry['enabledByDefault'])->toBeTrue();
    }
});
