<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\commerce\base\Purchasable;
use craft\commerce\Plugin as Commerce;
use GraphQL\Type\Definition\Type;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\gql\types\ContainerDepositType;
use johnhenry\containerdeposits\models\DepositType;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Assign a deposit type to a variant.
 * Place this field on your variant field layout (or the product's, for every
 * variant of it), then select the applicable deposit type (e.g. "Can €0.15").
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositTypeField extends Field implements PreviewableFieldInterface
{
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
        return Craft::t('container-deposits', 'Container Deposit Type');
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether the field can be marked as required.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function isRequirable(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     *
     * @param mixed $value The field value.
     * @param ElementInterface|null $element The element the field is on.
     * @return string The input HTML.
     * @throws Exception if the template can't be rendered.
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getInputHtml(mixed $value, ?ElementInterface $element): string
    {
        $depositTypes = ContainerDeposits::getInstance()->getDepositTypes()->getAllDepositTypes();
        $currency = $this->_currencyCode($element);

        $options = [['label' => Craft::t('container-deposits', 'None'), 'value' => '']];
        foreach ($depositTypes as $type) {
            $options[] = [
                'label' => $type->name . ' (' . Craft::$app->getFormatter()->asCurrency($type->amount, $currency) . ')',
                'value' => $type->id,
            ];
        }

        return Craft::$app->getView()->renderTemplate('_includes/forms/select', [
            'id' => $this->getInputId(),
            'describedBy' => $this->describedBy,
            'name' => $this->handle,
            'value' => $value instanceof DepositType ? $value->id : $value,
            'options' => $options,
        ]);
    }

    /**
     * @inheritdoc
     *
     * @param mixed $value The raw field value.
     * @param ElementInterface|null $element The element the field is on.
     * @return DepositType|null The resolved deposit type, or null.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element): ?DepositType
    {
        if ($value instanceof DepositType) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return ContainerDeposits::getInstance()->getDepositTypes()->getDepositTypeById((int)$value);
        }

        return null;
    }

    /**
     * @inheritdoc
     *
     * @param mixed $value The field value.
     * @param ElementInterface|null $element The element the field is on.
     * @return mixed The serialized deposit type ID, or null.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof DepositType) {
            return $value->id;
        }
        return $value ?: null;
    }

    /**
     * @inheritdoc
     *
     * @param mixed $value The field value.
     * @param ElementInterface $element The element the field is on.
     * @return string The preview HTML.
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if ($value instanceof DepositType) {
            return sprintf(
                '%s <span class="light">(%s)</span>',
                htmlspecialchars($value->name, ENT_QUOTES, 'UTF-8'),
                Craft::$app->getFormatter()->asCurrency($value->amount, $this->_currencyCode($element)),
            );
        }
        return '';
    }

    /**
     * @inheritdoc
     *
     * @return Type|array The GraphQL type for the field's value: the deposit type's ID, name, handle and amount.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function getContentGqlType(): Type|array
    {
        return ContainerDepositType::getType();
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the currency to show deposit amounts in: the element's store
     * currency for a purchasable, otherwise the current store's.
     *
     * @param ElementInterface|null $element The element the field is on.
     * @return string|null The currency code, or null to use the formatter's default.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _currencyCode(?ElementInterface $element): ?string
    {
        $store = $element instanceof Purchasable ? $element->getStore() : Commerce::getInstance()?->getStores()->getCurrentStore();

        return $store?->getCurrency()?->getCode();
    }
}
