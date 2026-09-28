<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits;

use Craft;
use craft\base\Plugin as BasePlugin;
use johnhenry\containerdeposits\base\PluginTrait;
use johnhenry\containerdeposits\services\ServicesTrait;

/**
 * Container Deposits plugin.
 *
 * Adds container deposits to Craft Commerce for any deposit return scheme,
 * Ireland's Re-turn or your own region's equivalent: configurable deposit
 * types, synthetic deposit purchasables, automatic cart line-item syncing, and
 * Re-turn-ready invoice/receipt rendering.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ContainerDeposits extends BasePlugin
{
    // Traits
    // =========================================================================

    use PluginTrait;
    use ServicesTrait;

    // Static Properties
    // =========================================================================

    /**
     * @var ContainerDeposits The plugin instance.
     */
    public static ContainerDeposits $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether the plugin has a CP section.
     */
    public bool $hasCpSection = true;

    /**
     * @var string The plugin's schema version.
     */
    public string $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->_registerElementTypes();
        $this->_registerFieldTypes();
        $this->_registerCartListeners();
        $this->_registerDiscountListeners();
        $this->_registerTwigVariable();
        $this->_registerGqlFields();
        $this->_registerMultiSiteHandlers();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpUrlRules();
        }
    }
}
