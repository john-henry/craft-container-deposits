<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\services;

use Craft;
use craft\base\Component;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\models\DepositType;
use johnhenry\containerdeposits\records\DepositTypeRecord;
use Throwable;

/**
 * Deposit type service.
 *
 * Reads, persists, and deletes {@see DepositType} models, keeping each one in
 * sync with its backing {@see DepositPurchasable} element. Results are memoized
 * for the duration of the request and reset whenever the underlying data
 * changes.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 *
 * @property-read DepositType[] $allDepositTypes
 */
class DepositTypeService extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * @var DepositType[]|null Memoized list of all deposit types.
     */
    private ?array $_allDepositTypes = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns every configured deposit type, ordered by sort order then name.
     *
     * @return DepositType[] The configured deposit types.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getAllDepositTypes(): array
    {
        if ($this->_allDepositTypes !== null) {
            return $this->_allDepositTypes;
        }

        $this->_allDepositTypes = [];
        /** @var DepositTypeRecord[] $records */
        $records = DepositTypeRecord::find()->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])->all();

        foreach ($records as $record) {
            $this->_allDepositTypes[] = $this->_createDepositTypeFromRecord($record);
        }

        return $this->_allDepositTypes;
    }

    /**
     * Returns the deposit type with the given ID, or null if none exists.
     *
     * @param int $id The deposit type ID.
     * @return DepositType|null The deposit type, or null.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDepositTypeById(int $id): ?DepositType
    {
        foreach ($this->getAllDepositTypes() as $type) {
            if ($type->id === $id) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Returns the deposit type with the given handle, or null if none exists.
     *
     * @param string $handle The deposit type handle.
     * @return DepositType|null The deposit type, or null.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDepositTypeByHandle(string $handle): ?DepositType
    {
        foreach ($this->getAllDepositTypes() as $type) {
            if ($type->handle === $handle) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Validates and saves a deposit type, syncing its backing purchasable.
     *
     * @param DepositType $model The deposit type to save.
     * @return bool Whether the deposit type was saved successfully.
     * @throws Throwable if the backing purchasable can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function saveDepositType(DepositType $model): bool
    {
        if (!$model->validate()) {
            return false;
        }

        $isNew = !$model->id;

        if (!$isNew) {
            $record = DepositTypeRecord::findOne($model->id);
            if (!$record) {
                $model->addError('id', Craft::t('container-deposits', 'No deposit type exists with the ID "{id}".', ['id' => $model->id]));
                return false;
            }
        } else {
            $record = new DepositTypeRecord();
        }

        $record->name = $model->name;
        $record->handle = $model->handle;
        $record->amount = $model->amount;
        $record->sortOrder = $model->sortOrder ?? 99;

        if (!$record->save()) {
            return false;
        }

        $model->id = $record->id;

        // Create or update the purchasable element for this deposit type
        if (!$this->_syncPurchasable($model)) {
            Craft::warning('Failed to sync purchasable for deposit type ' . $model->handle, 'container-deposits');
        }

        $this->_allDepositTypes = null;
        return true;
    }

    /**
     * Deletes the deposit type with the given ID and its backing purchasable.
     *
     * @param int $id The deposit type ID.
     * @return bool Whether a deposit type was deleted.
     * @throws Throwable if the backing element can't be deleted.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function deleteDepositTypeById(int $id): bool
    {
        $record = DepositTypeRecord::findOne($id);
        if (!$record) {
            return false;
        }

        // The purchasableId FK is SET NULL on delete, but we want to delete the element too
        if ($record->purchasableId) {
            Craft::$app->getElements()->deleteElementById($record->purchasableId);
        }

        $record->delete();
        $this->_allDepositTypes = null;

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates or updates the backing purchasable element for a deposit type and
     * stores the resulting element ID back on the deposit type record.
     *
     * @param DepositType $depositType The deposit type to sync.
     * @return bool Whether the purchasable was synced successfully.
     * @throws Throwable if the backing element can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _syncPurchasable(DepositType $depositType): bool
    {
        $record = DepositTypeRecord::findOne($depositType->id);
        if (!$record) {
            return false;
        }

        if ($record->purchasableId) {
            /** @var DepositPurchasable|null $purchasable */
            $purchasable = Craft::$app->getElements()->getElementById($record->purchasableId, DepositPurchasable::class);
        }

        if (empty($purchasable)) {
            $purchasable = new DepositPurchasable();
        }

        $purchasable->depositTypeId = $depositType->id;
        $saved = Craft::$app->getElements()->saveElement($purchasable, false);

        if (!$saved) {
            return false;
        }

        // Store the purchasable ID on the deposit type record
        if ((int)$record->purchasableId !== $purchasable->id) {
            $record->purchasableId = $purchasable->id;
            $record->save(false);
        }

        $depositType->purchasableId = $purchasable->id;
        return true;
    }

    /**
     * Builds a deposit type model from its active record.
     *
     * @param DepositTypeRecord $record The deposit type record.
     * @return DepositType The populated deposit type model.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _createDepositTypeFromRecord(DepositTypeRecord $record): DepositType
    {
        $model = new DepositType();
        $model->id = $record->id;
        $model->name = $record->name;
        $model->handle = $record->handle;
        $model->amount = $record->amount;
        $model->purchasableId = $record->purchasableId ? (int)$record->purchasableId : null;
        $model->sortOrder = $record->sortOrder ? (int)$record->sortOrder : null;
        $model->uid = $record->uid;
        return $model;
    }
}
