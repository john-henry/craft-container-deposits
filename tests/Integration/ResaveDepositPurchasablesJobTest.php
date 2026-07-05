<?php

use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\jobs\ResaveDepositPurchasables;

it('runs without error when there are no deposit types', function () {
    $job = new ResaveDepositPurchasables();
    $job->execute(Craft::$app->getQueue());

    // No exception thrown is the assertion for the empty-set fast path.
    expect(true)->toBeTrue();
});

it('resaves every deposit purchasable so new-site rows are filled in', function () {
    $type = makeDepositType('Job Deposit', 'jobDeposit', 0.15);

    /** @var DepositPurchasable $before */
    $before = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);
    expect($before)->toBeInstanceOf(DepositPurchasable::class);

    $job = new ResaveDepositPurchasables();
    $job->execute(Craft::$app->getQueue());

    // The purchasable still resolves after the resave (it wasn't dropped).
    $after = Craft::$app->getElements()->getElementById($type->purchasableId, DepositPurchasable::class);
    expect($after)->toBeInstanceOf(DepositPurchasable::class);
    expect($after->depositTypeId)->toBe($type->id);
});
