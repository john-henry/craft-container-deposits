<?php

/**
 * Covers the DepositTypeField value handling: normalizing a stored ID back into
 * a DepositType model, and serializing a model back down to its ID for storage.
 * This is what links a product/variant to its deposit tier.
 */

use johnhenry\containerdeposits\fields\DepositTypeField;
use johnhenry\containerdeposits\models\DepositType;

beforeEach(function () {
    $this->field = new DepositTypeField(['handle' => 'depositType']);
});

it('normalizes a stored ID into the matching deposit type', function () {
    $type = makeDepositType('Can Deposit', 'canDepositNormalize', 0.15);

    $normalized = $this->field->normalizeValue($type->id, null);

    expect($normalized)->toBeInstanceOf(DepositType::class);
    expect($normalized->id)->toBe($type->id);
});

it('passes a deposit type through normalization untouched', function () {
    $type = makeDepositType('Can Deposit', 'canDepositPassthrough', 0.15);

    expect($this->field->normalizeValue($type, null))->toBe($type);
});

it('normalizes an empty value to null', function () {
    expect($this->field->normalizeValue(null, null))->toBeNull();
    expect($this->field->normalizeValue('', null))->toBeNull();
});

it('serializes a deposit type down to its ID', function () {
    $type = makeDepositType('Can Deposit', 'canDepositSerialize', 0.15);

    expect($this->field->serializeValue($type, null))->toBe($type->id);
});

it('serializes an empty value to null', function () {
    expect($this->field->serializeValue('', null))->toBeNull();
    expect($this->field->serializeValue(null, null))->toBeNull();
});

it('cannot be marked required', function () {
    expect(DepositTypeField::isRequirable())->toBeFalse();
});
