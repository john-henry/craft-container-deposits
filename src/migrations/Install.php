<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\migrations;

use Craft;
use craft\commerce\models\TaxCategory;
use craft\commerce\Plugin as Commerce;
use craft\db\Migration;

/**
 * Install migration.
 *
 * Creates the deposit type and deposit purchasable tables, their indexes and
 * foreign keys, and ensures the dedicated "Container Deposits (No VAT)" tax
 * category exists.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class Install extends Migration
{
    // Constants
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
     * @throws \Throwable if the tax category can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function safeUp(): bool
    {
        if ($this->createTables()) {
            $this->createIndexes();
            $this->addForeignKeys();
            Craft::$app->db->schema->refresh();
        }

        $this->ensureTaxCategory();

        return true;
    }

    /**
     * Drops the plugin's tables.
     *
     * @return bool Whether the migration reverted successfully.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function safeDown(): bool
    {
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
     * @throws \Throwable if the tax category can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
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
        $taxCategory->description = 'Irish DRS deposits — treated as outside the scope of VAT by Revenue.';
        $taxCategory->default = false;

        $commerce->getTaxCategories()->saveTaxCategory($taxCategory);
    }

    /**
     * Creates the plugin's database tables if they don't already exist.
     *
     * @return bool Whether the tables were created (or already existed).
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function createTables(): bool
    {
        if (!$this->db->tableExists('{{%containerdeposits_types}}')) {
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
            $this->createTable('{{%containerdeposits_purchasables}}', [
                'id' => $this->integer()->notNull(),
                'depositTypeId' => $this->integer()->notNull(),
                'PRIMARY KEY([[id]])',
            ]);
        }

        return true;
    }

    /**
     * Creates the plugin's table indexes.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
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
