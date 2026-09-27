<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\migrations;

use craft\commerce\models\TaxCategory;
use craft\commerce\Plugin as Commerce;
use craft\db\Migration;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use Throwable;

/**
 * Install migration.
 *
 * Creates the deposit type and deposit purchasable tables, their indexes and
 * foreign keys, and ensures the dedicated "Container Deposits (No VAT)" tax
 * category exists.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class Install extends Migration
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The handle of the no-VAT tax category created for deposits.
     */
    public const TAX_CATEGORY_HANDLE = 'containerDeposit';

    // Public Methods
    // =========================================================================

    /**
     * Creates the plugin's tables and tax category.
     *
     * @return bool Whether the migration applied successfully.
     * @throws Throwable if the tax category can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function safeUp(): bool
    {
        if ($this->createTables()) {
            $this->createIndexes();
            $this->addForeignKeys();
            $this->db->getSchema()->refresh();
        }

        $this->ensureTaxCategory();

        return true;
    }

    /**
     * Removes the deposit purchasables, the no-VAT tax category and the
     * plugin's tables.
     *
     * The purchasables go first, straight from the elements table: their
     * Commerce purchasable, store and catalog price rows cascade with them, so
     * nothing is left for a cart to point at once the plugin's gone.
     *
     * @return bool Whether the migration reverted successfully.
     * @throws Throwable if the tax category can't be deleted.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function safeDown(): bool
    {
        $this->delete('{{%elements}}', ['type' => DepositPurchasable::class]);

        $taxCategories = Commerce::getInstance()?->getTaxCategories();
        $taxCategory = $taxCategories?->getTaxCategoryByHandle(self::TAX_CATEGORY_HANDLE);
        if ($taxCategory?->id !== null && !$taxCategory->default) {
            $taxCategories->deleteTaxCategoryById($taxCategory->id);
        }

        $this->dropTableIfExists('{{%containerdeposits_purchasables}}');
        $this->dropTableIfExists('{{%containerdeposits_types}}');
        return true;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates a "Container Deposits (No VAT)" tax category if it doesn't exist.
     * Irish Revenue treats DRS deposits as outside the scope of VAT.
     *
     * @return void
     * @throws Throwable if the tax category can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function ensureTaxCategory(): void
    {
        $commerce = Commerce::getInstance();
        if (!$commerce) {
            return;
        }

        $existing = $commerce->getTaxCategories()->getTaxCategoryByHandle(self::TAX_CATEGORY_HANDLE);
        if ($existing) {
            return;
        }

        $taxCategory = new TaxCategory();
        $taxCategory->name = 'Container Deposits (No VAT)';
        $taxCategory->handle = self::TAX_CATEGORY_HANDLE;
        $taxCategory->description = 'Irish DRS deposits, treated as outside the scope of VAT by Revenue.';
        $taxCategory->default = false;

        $commerce->getTaxCategories()->saveTaxCategory($taxCategory);
    }

    /**
     * Creates the plugin's database tables if they don't already exist.
     *
     * Returns false when both tables were already there, so a reinstall over
     * surviving tables doesn't add their indexes and foreign keys twice.
     *
     * @return bool Whether any table was created.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function createTables(): bool
    {
        $created = false;

        if (!$this->db->tableExists('{{%containerdeposits_types}}')) {
            $created = true;
            $this->createTable('{{%containerdeposits_types}}', [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string()->notNull(),
                'amount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
                'purchasableId' => $this->integer()->null(),
                'sortOrder' => $this->integer()->unsigned()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists('{{%containerdeposits_purchasables}}')) {
            $created = true;
            $this->createTable('{{%containerdeposits_purchasables}}', [
                'id' => $this->integer()->notNull(),
                'depositTypeId' => $this->integer()->notNull(),
                'PRIMARY KEY([[id]])',
            ]);
        }

        return $created;
    }

    /**
     * Creates the plugin's table indexes.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function createIndexes(): void
    {
        $this->createIndex(null, '{{%containerdeposits_types}}', ['handle'], true);
        $this->createIndex(null, '{{%containerdeposits_types}}', ['purchasableId']);
        $this->createIndex(null, '{{%containerdeposits_purchasables}}', ['depositTypeId']);
    }

    /**
     * Creates the plugin's foreign keys.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function addForeignKeys(): void
    {
        $this->addForeignKey(
            null,
            '{{%containerdeposits_types}}', 'purchasableId',
            '{{%elements}}', 'id',
            'SET NULL', 'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%containerdeposits_purchasables}}', 'id',
            '{{%elements}}', 'id',
            'CASCADE', 'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%containerdeposits_purchasables}}', 'depositTypeId',
            '{{%containerdeposits_types}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }
}
