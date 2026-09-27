<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\events;

use craft\commerce\models\LineItem;
use yii\base\Event;

/**
 * Lets another plugin say what a line item contains, so the containers inside
 * it carry their deposit.
 *
 * A bundle or gift box line points at a purchasable that has no deposit type
 * of its own, while the cans or bottles packed into it do. Each entry in
 * `$contents` is one purchasable inside a single unit of the line, and how
 * many of it: a 24-can crate adds its can variant with a `qty` of 24. The line
 * quantity and the purchasable's containers per unit are applied on top.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.1.0
 */
class DefineLineItemContentsEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var LineItem The product line item being reconciled.
     */
    public LineItem $lineItem;

    /**
     * @var array<int, array<string, mixed>> The purchasables inside one unit of the line, each as
     * `['purchasable' => ElementInterface, 'qty' => int]`. Entries that don't fit that shape, or have no positive
     * `qty`, are skipped.
     */
    public array $contents = [];
}
