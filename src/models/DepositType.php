<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\models;

use Craft;
use craft\base\Model;
use johnhenry\containerdeposits\records\DepositTypeRecord;

/**
 * Deposit type model.
 *
 * Represents a single configurable container-deposit tier (e.g. "Can €0.15",
 * "Bottle €0.25"). Each deposit type is backed by a synthetic
 * {@see \johnhenry\containerdeposits\elements\DepositPurchasable} so it can be
 * added to a cart as a line item.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositType extends Model
{
    // Properties
    // =========================================================================

    /**
     * @var int|null The deposit type's ID.
     */
    public ?int $id = null;

    /**
     * @var string The deposit type's name (shown on the cart line item).
     */
    public string $name = '';

    /**
     * @var string The deposit type's handle (unique internal identifier).
     */
    public string $handle = '';

    /**
     * @var float The deposit amount in the store currency.
     */
    public float $amount = 0.0;

    /**
     * @var int|null The ID of the backing purchasable element.
     */
    public ?int $purchasableId = null;

    /**
     * @var int|null The deposit type's sort order.
     */
    public ?int $sortOrder = null;

    /**
     * @var string|null The deposit type's UID.
     */
    public ?string $uid = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The validation rules.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => Craft::t('container-deposits', '{attribute} must start with a letter and contain only letters, numbers, and underscores.')],
            [['handle'], 'validateHandleUniqueness'],
            [['amount'], 'number', 'min' => 0],
            [['amount'], 'required'],
        ];
    }

    /**
     * Validates that the handle isn't already in use by another deposit type.
     * Registered as an inline validator via {@see defineRules()}.
     *
     * @param string $attribute The attribute being validated (always "handle").
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function validateHandleUniqueness(string $attribute): void
    {
        if ($this->$attribute === '') {
            return;
        }

        $query = DepositTypeRecord::find()->where(['handle' => $this->$attribute]);

        if ($this->id !== null) {
            $query->andWhere(['not', ['id' => $this->id]]);
        }

        if ($query->exists()) {
            $this->addError($attribute, Craft::t('container-deposits', '{attribute} "{handle}" has already been taken.', [
                'attribute' => 'Handle',
                'handle' => $this->$attribute,
            ]));
        }
    }
}
