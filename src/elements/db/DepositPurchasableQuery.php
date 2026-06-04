<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\elements\db;

use craft\commerce\elements\db\PurchasableQuery;
use craft\helpers\Db;
use yii\base\NotSupportedException;

/**
 * Deposit purchasable element query.
 *
 * Adds a `depositTypeId` query param so deposit purchasables can be filtered by
 * the deposit type they back.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositPurchasableQuery extends PurchasableQuery
{
    // Properties
    // =========================================================================

    /**
     * @var int|null The deposit type ID to filter results by.
     */
    public ?int $depositTypeId = null;

    // Public Methods
    // =========================================================================

    /**
     * Narrows the query results to deposit purchasables backing the given
     * deposit type.
     *
     * @param int $depositTypeId The deposit type ID.
     * @return static self reference.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function depositTypeId(int $depositTypeId): static
    {
        $this->depositTypeId = $depositTypeId;
        return $this;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the query should be prepared and executed.
     * @throws NotSupportedException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function beforePrepare(): bool
    {
        $this->joinElementTable('{{%containerdeposits_purchasables}}');
        $this->query->addSelect(['containerdeposits_purchasables.depositTypeId']);

        if ($this->depositTypeId !== null) {
            $this->subQuery->andWhere(Db::parseParam('[[containerdeposits_purchasables.depositTypeId]]', $this->depositTypeId));
        }

        return parent::beforePrepare();
    }
}
