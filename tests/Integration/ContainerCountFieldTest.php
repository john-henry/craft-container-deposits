<?php

use johnhenry\containerdeposits\fields\ContainerCountField;

describe('ContainerCountField normalization', function () {
    beforeEach(function () {
        $this->field = new ContainerCountField();
    });

    it('defaults missing values to 1', function () {
        expect($this->field->normalizeValue(null, null))->toBe(1);
        expect($this->field->normalizeValue('', null))->toBe(1);
    });

    it('casts numeric strings to int', function () {
        expect($this->field->normalizeValue('6', null))->toBe(6);
        expect($this->field->normalizeValue('24', null))->toBe(24);
    });

    it('clamps to minimum of 1', function () {
        expect($this->field->normalizeValue(0, null))->toBe(1);
        expect($this->field->normalizeValue(-5, null))->toBe(1);
    });

    it('respects a custom defaultContainerCount', function () {
        $field = new ContainerCountField();
        $field->defaultContainerCount = 6;
        expect($field->normalizeValue(null, null))->toBe(6);
    });

    it('reports the correct display name', function () {
        expect(ContainerCountField::displayName())->toContain('Containers Per Unit');
    });
});
