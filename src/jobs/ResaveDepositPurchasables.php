<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\jobs;

use Craft;
use craft\queue\BaseJob;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use Throwable;

/**
 * Resave deposit purchasables job.
 *
 * Re-saves every {@see DepositPurchasable} so Craft fills in the
 * `elements_sites` rows for any newly added site (via
 * {@see DepositPurchasable::getSupportedSites()}). Pushed from the
 * `Sites::EVENT_AFTER_SAVE_SITE` handler so the resave loop runs on the queue
 * and doesn't hold up saving the site.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ResaveDepositPurchasables extends BaseJob
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param \craft\queue\QueueInterface|\yii\queue\Queue $queue The queue the job belongs to.
     * @return void
     * @throws Throwable if a purchasable can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function execute($queue): void
    {
        $depositTypes = ContainerDeposits::getInstance()->getDepositTypes()->getAllDepositTypes();
        $total = count($depositTypes);

        if ($total === 0) {
            return;
        }

        $elementsService = Craft::$app->getElements();
        $step = 0;

        foreach ($depositTypes as $depositType) {
            $this->setProgress($queue, $step++ / $total);

            if (!$depositType->purchasableId) {
                continue;
            }

            // Query across all sites: queue workers run in primary-site context,
            // so an unqualified lookup would miss purchasables on other sites.
            /** @var DepositPurchasable|null $purchasable */
            $purchasable = DepositPurchasable::find()
                ->id($depositType->purchasableId)
                ->site('*')
                ->status(null)
                ->one();

            if ($purchasable) {
                $elementsService->saveElement($purchasable, false);
            }
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string|null The default job description.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('container-deposits', 'Propagating deposit purchasables to all sites');
    }
}
