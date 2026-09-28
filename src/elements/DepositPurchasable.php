<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\elements;

use Craft;
use craft\commerce\base\Purchasable;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\elements\db\DepositPurchasableQuery;
use johnhenry\containerdeposits\migrations\Install;
use johnhenry\containerdeposits\models\DepositType;
use johnhenry\containerdeposits\records\DepositPurchasableRecord;
use Throwable;

/**
 * Deposit purchasable element.
 *
 * A synthetic Commerce purchasable backing a single
 * {@see DepositType}. These are created and
 * maintained by the plugin (not by merchandisers) and propagated to every site
 * so the cart sync can resolve a deposit purchasable in any store/site context.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 *
 * @property-read null|DepositType $depositType
 */
class DepositPurchasable extends Purchasable
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null The ID of the deposit type this purchasable represents.
     */
    public ?int $depositTypeId = null;

    // Private Properties
    // =========================================================================

    /**
     * @var DepositType|null Memoized deposit type for this purchasable.
     */
    private ?DepositType $_depositType = null;

    /**
     * @var bool Whether the missing tax category has been logged this request.
     */
    private static bool $_loggedMissingTaxCategory = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The display name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('container-deposits', 'Deposit');
    }

    /**
     * @inheritdoc
     *
     * @return string The plural display name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('container-deposits', 'Deposits');
    }

    /**
     * @inheritdoc
     *
     * @return string|null The reference handle.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function refHandle(): ?string
    {
        return 'depositPurchasable';
    }

    /**
     * @inheritdoc
     *
     * A deposit purchasable is a fixed-fee line item with no stock to track;
     * it must never appear on Commerce's Inventory index.
     *
     * @return bool Whether this purchasable type has inventory.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function hasInventory(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     *
     * @return DepositPurchasableQuery The element query.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function find(): DepositPurchasableQuery
    {
        return new DepositPurchasableQuery(static::class);
    }

    /**
     * Lists every site, enabled or not.
     *
     * Deposit purchasables aren't localized, so Craft keeps and finds them on
     * the primary site whatever site a cart is on. Listing every site keeps
     * that true whichever site is primary.
     *
     * @return array The supported site definitions.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSupportedSites(): array
    {
        $siteIds = [];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $siteIds[] = [
                'siteId' => $site->id,
                'propagate' => true,
                'enabledByDefault' => true,
            ];
        }
        return $siteIds;
    }

    /**
     * Returns the deposit type this purchasable represents.
     *
     * @return DepositType|null The deposit type, or null if not set.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDepositType(): ?DepositType
    {
        if ($this->_depositType === null && $this->depositTypeId) {
            $this->_depositType = ContainerDeposits::getInstance()->getDepositTypes()->getDepositTypeById($this->depositTypeId);
        }
        return $this->_depositType;
    }

    /**
     * @inheritdoc
     *
     * @return float The deposit price.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getPrice(): float
    {
        return (float)($this->getDepositType()->amount ?? 0);
    }

    /**
     * A deposit is always its full amount, so it never has a promotional
     * price, whatever catalog pricing rules the store runs.
     *
     * @return float|null Always null.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function getPromotionalPrice(): ?float
    {
        return null;
    }

    /**
     * @inheritdoc
     *
     * @return float The deposit price.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function getSalePrice(): float
    {
        return $this->getPrice();
    }

    /**
     * @inheritdoc
     *
     * @return string The purchasable SKU.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSku(): string
    {
        $type = $this->getDepositType();
        return $type ? 'deposit-' . $type->handle : 'deposit-' . $this->id;
    }

    /**
     * @inheritdoc
     *
     * @return string The purchasable description.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDescription(): string
    {
        return $this->getDepositType()->name ?? Craft::t('container-deposits', 'Deposit');
    }

    /**
     * @inheritdoc
     *
     * @return array The purchasable snapshot.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSnapshot(): array
    {
        $type = $this->getDepositType();
        return [
            'depositTypeId' => $this->depositTypeId,
            'depositTypeName' => $type?->name,
            'price' => $this->getPrice(),
            'sku' => $this->getSku(),
        ];
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether the purchasable has free shipping.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function hasFreeShipping(): bool
    {
        return true;
    }

    /**
     * A deposit isn't a thing that ships, so it's left out of shipping rules'
     * category and quantity checks.
     *
     * @return bool Always false.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function getIsShippable(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether the purchasable is promotable.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getIsPromotable(): bool
    {
        return false;
    }

    /**
     * Irish DRS deposits are outside the scope of VAT (Revenue guidance).
     * Returns the dedicated "Container Deposits (No VAT)" tax category, falling
     * back to the store default if the category has been deleted by an admin.
     * That fallback can charge VAT on deposits, so it's logged as a warning.
     *
     * @return int The tax category ID.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getTaxCategoryId(): int
    {
        $taxCategory = Commerce::getInstance()
            ?->getTaxCategories()
            ->getTaxCategoryByHandle(Install::TAX_CATEGORY_HANDLE);

        if ($taxCategory?->id !== null) {
            return $taxCategory->id;
        }

        if (!self::$_loggedMissingTaxCategory) {
            Craft::warning(sprintf(
                'The "%s" tax category is missing, so deposits fall back to the default tax category and may be charged VAT. Reinstall the plugin or recreate the category to fix it.',
                Install::TAX_CATEGORY_HANDLE,
            ), 'container-deposits');
            self::$_loggedMissingTaxCategory = true;
        }

        return parent::getTaxCategoryId();
    }

    /**
     * @inheritdoc
     *
     * A deposit type is available whenever it still exists, including
     * intentional zero-amount tiers (e.g. a "free return" promotion). Only a
     * missing/deleted deposit type makes the purchasable unavailable.
     *
     * @return bool Whether the purchasable is available for purchase.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getIsAvailable(): bool
    {
        return $this->getDepositType() !== null;
    }

    /**
     * @inheritdoc
     *
     * @param LineItem $lineItem The line item to populate.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function populateLineItem(LineItem $lineItem): void
    {
        $lineItem->setPrice($this->getPrice());
        $lineItem->setPromotionalPrice(null);
    }

    /**
     * @inheritdoc
     *
     * @param bool $isNew Whether the element is brand new.
     * @return void
     * @throws Throwable if the backing record can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function afterSave(bool $isNew): void
    {
        $record = $isNew ? new DepositPurchasableRecord() : (DepositPurchasableRecord::findOne($this->id) ?? new DepositPurchasableRecord());

        $record->id = $this->id;
        $record->depositTypeId = $this->depositTypeId;
        $record->save(false);

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     *
     * @param LineItem $lineItem The line item to validate.
     * @return array The line item validation rules.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getLineItemRules(LineItem $lineItem): array
    {
        return [];
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The table attribute definitions.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'sku' => Craft::t('commerce', 'SKU'),
            'price' => Craft::t('commerce', 'Price'),
        ];
    }
}
