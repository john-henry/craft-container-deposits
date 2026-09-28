<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\services;

use yii\base\InvalidConfigException;

/**
 * ServicesTrait
 *
 * Wires the plugin's service components and exposes typed accessors that narrow
 * Yii's `Component::get()` return type for static analysis.
 *
 * @property DepositCartService $depositCart
 * @property DepositTypeService $depositTypes
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
trait ServicesTrait
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The plugin's component configuration.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'depositTypes' => DepositTypeService::class,
                'depositCart' => DepositCartService::class,
            ],
        ];
    }

    /**
     * Returns the deposit types service.
     *
     * @return DepositTypeService The deposit types service.
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function getDepositTypes(): DepositTypeService
    {
        $component = $this->get('depositTypes');
        assert($component instanceof DepositTypeService);
        return $component;
    }

    /**
     * Returns the deposit cart service.
     *
     * @return DepositCartService The deposit cart service.
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function getDepositCart(): DepositCartService
    {
        $component = $this->get('depositCart');
        assert($component instanceof DepositCartService);
        return $component;
    }
}
