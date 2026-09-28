<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\records;

use craft\db\ActiveRecord;

/**
 * Deposit purchasable active record.
 *
 * Maps a {@see \johnhenry\containerdeposits\elements\DepositPurchasable} element
 * ID to the deposit type it backs.
 *
 * @property int $id The purchasable element ID.
 * @property int $depositTypeId The deposit type ID this purchasable backs.
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositPurchasableRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the name of the database table this record uses.
     *
     * @return string The table name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function tableName(): string
    {
        return '{{%containerdeposits_purchasables}}';
    }
}
