<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\fields;

use Craft;
use craft\base\ElementInterface;
use craft\fields\Number;

/**
 * Field that captures how many deposit-bearing containers are inside a single
 * unit of the purchasable. For a single 500 ml bottle this is 1; for a 6-pack
 * it is 6; for a tray of 24 cans it is 24.
 *
 * The cart service multiplies a line item's qty by this value when working out
 * the expected deposit qty, so a cart of 2 × 6-packs comes to 12 deposits.
 *
 * If the field isn't on the layout, or is left empty, the count falls back to
 * 1 and the product just gets a single deposit per unit.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 *
 * @property-read array[] $elementValidationRules
 */
class ContainerCountField extends Number
{
    // Properties
    // =========================================================================

    /**
     * @var int The fallback container count when the field value is empty.
     */
    public int $defaultContainerCount = 1;

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
        return Craft::t('container-deposits', 'Containers Per Unit (Deposit)');
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param array $config The field configuration.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function __construct($config = [])
    {
        // Sensible defaults for a "containers per unit" counter
        $config['min'] = $config['min'] ?? 1;
        $config['decimals'] = $config['decimals'] ?? 0;
        $config['defaultValue'] = $config['defaultValue'] ?? 1;

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     *
     * @return array The element validation rules.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getElementValidationRules(): array
    {
        return [
            ['integer', 'min' => 1],
        ];
    }

    /**
     * @inheritdoc
     *
     * @param mixed $value The raw field value.
     * @param ElementInterface|null $element The element the field is on.
     * @return mixed The normalized container count.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        // Handle an empty value ourselves first. If we let the parent see it,
        // it swaps in the field's own defaultValue and our defaultContainerCount
        // never gets a look in.
        if ($value === null || $value === '') {
            return $this->defaultContainerCount;
        }
        $value = parent::normalizeValue($value, $element);
        return max(1, (int)$value);
    }
}
