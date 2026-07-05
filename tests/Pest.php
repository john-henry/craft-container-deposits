<?php

/**
 * Pest harness for the Container Deposits plugin.
 *
 * Feature tests run without a Craft application instance; any test that
 * touches Craft::$app or Craft::t() must live under Integration/ and use the
 * RefreshesDatabase trait so the test DB is rolled back after each test.
 *
 * Run from the parent Craft project:
 *
 *   vendor/bin/pest plugins/craft-container-deposits/tests \
 *     --test-directory=plugins/craft-container-deposits/tests
 */

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\models\DepositType;
use johnhenry\containerdeposits\records\DepositTypeRecord;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;

uses(
    TestCase::class,
    RefreshesDatabase::class,
)->in('Integration');

// Reset the deposit-types service cache before every Integration test, so the
// service re-reads from the DB after RefreshesDatabase rolls back the previous
// test's transaction.
beforeEach(function () {
    $service = ContainerDeposits::getInstance()->depositTypes;
    $prop = new \ReflectionProperty($service, '_allDepositTypes');
    $prop->setAccessible(true);
    $prop->setValue($service, null);
})->in('Integration');

// ---------------------------------------------------------------------------
// Shared integration helpers
// ---------------------------------------------------------------------------

/**
 * Creates (or reuses) a deposit type by handle. Upsert-style: if a deposit
 * type already exists with the same handle (e.g. left behind by CP usage
 * outside of tests), it is updated and returned so tests can run idempotently
 * against any DB state.
 */
function makeDepositType(string $name = 'Can Deposit', string $handle = 'canDeposit', float $amount = 0.15): DepositType
{
    // Belt-and-braces: drop any existing record with this handle that leaked
    // through from a prior test (transaction rollback isn't always tight when
    // synthetic Element saves are involved). Inside the current test's
    // transaction, this DELETE is itself rolled back at teardown.
    DepositTypeRecord::deleteAll(['handle' => $handle]);

    // Reset the service cache so it re-reads after the delete.
    $service = ContainerDeposits::getInstance()->depositTypes;
    $prop = new \ReflectionProperty($service, '_allDepositTypes');
    $prop->setAccessible(true);
    $prop->setValue($service, null);

    $type         = new DepositType();
    $type->name   = $name;
    $type->handle = $handle;
    $type->amount = $amount;

    if (!$service->saveDepositType($type)) {
        throw new RuntimeException(
            'Could not save deposit type: ' . implode(', ', $type->getErrorSummary(true))
        );
    }

    return $type;
}

/**
 * Minimal saved cart for line item sync tests.
 */
function makeCart(string $email = 'test@example.com'): Order
{
    $order = new Order();
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->email  = $email;
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException(
            'Could not save test cart: ' . implode(', ', $order->getErrorSummary(true))
        );
    }

    return $order;
}
