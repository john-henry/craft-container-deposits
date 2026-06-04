<?php

/**
 * Pure tests for the Twig variable's formatting helper.
 * Lives in Feature/ because formatAmount() with an explicit currency code
 * doesn't need a running Commerce store.
 */

use johnhenry\containerdeposits\variables\ContainerDepositsVariable;

describe('ContainerDepositsVariable::formatAmount', function () {
    beforeEach(function () {
        $this->variable = new ContainerDepositsVariable();
    });

    it('renders sub-€1 amounts as cents (Re-turn preferred style)', function () {
        expect($this->variable->formatAmount(0.15, 'EUR'))->toBe('15c');
        expect($this->variable->formatAmount(0.25, 'EUR'))->toBe('25c');
        expect($this->variable->formatAmount(0.90, 'EUR'))->toBe('90c');
    });

    it('renders €1+ amounts in currency notation', function () {
        expect($this->variable->formatAmount(1.00, 'EUR'))->toContain('1');
        expect($this->variable->formatAmount(3.60, 'EUR'))->toContain('3');
    });

    it('rounds the cents value cleanly', function () {
        // €0.149 should round to 15c, not display as 14.9c
        expect($this->variable->formatAmount(0.149, 'EUR'))->toBe('15c');
    });

    it('handles zero', function () {
        expect($this->variable->formatAmount(0.0, 'EUR'))->toBe('0c');
    });
});
