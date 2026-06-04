<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\models\DepositType;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Assign a deposit type to a product or variant.
 * Place this field on your product/variant field layout, then select the
 * applicable deposit type (e.g. "Can €0.15", "Bottle €0.25").
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositTypeField extends Field implements PreviewableFieldInterface
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The display name.
     * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function isRequirable(): bool
    {
        return false;
    }

    // Public Methods
    // =========================================================================

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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getInputHtml(mixed $value, ?ElementInterface $element): string
    {
        $depositTypes = ContainerDeposits::getInstance()->depositTypes->getAllDepositTypes();

        $options = [['label' => Craft::t('container-deposits', '— None —'), 'value' => '']];
        foreach ($depositTypes as $type) {
            $options[] = [
                'label' => $type->name . ' (' . Craft::$app->getFormatter()->asCurrency($type->amount) . ')',
                'value' => $type->id,
            ];
        }

        return Craft::$app->getView()->renderTemplate('_includes/forms/select', [
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element): ?DepositType
    {
        if ($value instanceof DepositType) {
            return $value;
        }

        if ($value) {
            return ContainerDeposits::getInstance()->depositTypes->getDepositTypeById((int)$value);
        }

        return null;
    }

    /**
     * @inheritdoc
     *
     * @param mixed $value The field value.
     * @param ElementInterface|null $element The element the field is on.
     * @return mixed The serialized deposit type ID, or null.
     * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if ($value instanceof DepositType) {
            return sprintf(
                '%s <span style="color:#8f98a3">(%s)</span>',
                htmlspecialchars($value->name, ENT_QUOTES, 'UTF-8'),
                Craft::$app->getFormatter()->asCurrency($value->amount),
            );
        }
        return '';
    }
}
