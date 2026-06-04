<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\records;

use craft\db\ActiveRecord;

/**
 * Deposit type active record.
 *
 * Persists a single deposit type tier and the ID of its backing purchasable
 * element.
 *
 * @property int $id The deposit type's ID.
 * @property string $name The deposit type's name.
 * @property string $handle The deposit type's handle.
 * @property float $amount The deposit amount in the store currency.
 * @property int|null $purchasableId The backing purchasable element ID.
 * @property int|null $sortOrder The deposit type's sort order.
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositTypeRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the name of the database table this record uses.
     *
     * @return string The table name.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function tableName(): string
    {
        return '{{%containerdeposits_types}}';
    }
}
