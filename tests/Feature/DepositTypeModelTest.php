<?php

use johnhenry\containerdeposits\models\DepositType;

describe('DepositType validation', function () {
    it('requires a name', function () {
        $type = new DepositType();
        $type->name = '';
        $type->handle = 'canDeposit';
        $type->amount = 0.15;

        expect($type->validate(['name']))->toBeFalse();
        expect($type->getErrors('name'))->not->toBeEmpty();
    });

    it('requires a handle', function () {
        $type = new DepositType();
        $type->name = 'Can Deposit';
        $type->handle = '';
        $type->amount = 0.15;

        expect($type->validate(['handle']))->toBeFalse();
        expect($type->getErrors('handle'))->not->toBeEmpty();
    });

    it('rejects handles starting with a digit', function () {
        $type = new DepositType();
        $type->name = 'Can Deposit';
        $type->handle = '15cDeposit';
        $type->amount = 0.15;

        expect($type->validate(['handle']))->toBeFalse();
    });

    it('rejects handles with hyphens', function () {
        $type = new DepositType();
        $type->name = 'Can Deposit';
        $type->handle = 'can-deposit';
        $type->amount = 0.15;

        expect($type->validate(['handle']))->toBeFalse();
    });

    it('accepts camelCase handles', function () {
        $type = new DepositType();
        $type->name = 'Can Deposit';
        $type->handle = 'canDeposit';
        $type->amount = 0.15;

        expect($type->validate(['handle']))->toBeTrue();
    });

    it('rejects negative amounts', function () {
        $type = new DepositType();
        $type->name = 'Can Deposit';
        $type->handle = 'canDeposit';
        $type->amount = -0.15;

        expect($type->validate(['amount']))->toBeFalse();
    });

    it('accepts zero amount', function () {
        $type = new DepositType();
        $type->name = 'Promotional Free Return';
        $type->handle = 'freeReturn';
        $type->amount = 0.0;

        expect($type->validate(['amount']))->toBeTrue();
    });

    it('accepts the Re-turn standard tiers', function () {
        $small = new DepositType(['name' => 'Small Container', 'handle' => 'smallContainer', 'amount' => 0.15]);
        $large = new DepositType(['name' => 'Large Container', 'handle' => 'largeContainer', 'amount' => 0.25]);

        expect($small->validate())->toBeTrue();
        expect($large->validate())->toBeTrue();
    });
});
