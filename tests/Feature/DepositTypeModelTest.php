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
        // Uses a handle distinct from any seeded/CP-created deposit type
        // (e.g. "canDeposit"), this is a Feature test with no transactional
        // rollback, so validateHandleUniqueness would otherwise collide with
        // real DB state left behind by other tests or CP usage.
        $type = new DepositType();
        $type->name = 'Bottle Deposit';
        $type->handle = 'bottleDepositCamelCase';
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

    it('has a validateHandleUniqueness rule wired into defineRules()', function () {
        $type = new DepositType();
        $rules = $type->defineRules();

        $hasUniquenessRule = false;
        foreach ($rules as $rule) {
            if (in_array('validateHandleUniqueness', (array)($rule[1] ?? null), true) || ($rule[1] ?? null) === 'validateHandleUniqueness') {
                $hasUniquenessRule = true;
                break;
            }
        }

        expect($hasUniquenessRule)->toBeTrue();
    });
});
