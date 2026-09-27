<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\controllers;

use Craft;
use craft\web\Controller;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\models\DepositType;
use Throwable;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Deposit Types controller.
 *
 * Handles the control panel CRUD screens for deposit types: listing, editing,
 * saving, and deleting. All actions require an admin account.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositTypesController extends Controller
{
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    // Public Methods
    // =========================================================================

    /**
     * Renders the deposit types index.
     *
     * @return Response The rendering result.
     * @throws ForbiddenHttpException if the user is not an admin.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        $depositTypes = ContainerDeposits::getInstance()->getDepositTypes()->getAllDepositTypes();

        return $this->renderTemplate('container-deposits/deposit-types/_index', [
            'depositTypes' => $depositTypes,
        ]);
    }

    /**
     * Renders the deposit type edit screen.
     *
     * @param int|null $id The deposit type ID, or null to create a new one.
     * @param DepositType|null $depositType A deposit type that failed to save, shown with its errors.
     * @return Response The rendering result.
     * @throws ForbiddenHttpException if the user is not an admin.
     * @throws NotFoundHttpException if the deposit type can't be found.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionEdit(?int $id = null, ?DepositType $depositType = null): Response
    {
        $this->requireAdmin(false);

        if ($depositType === null && $id) {
            $depositType = ContainerDeposits::getInstance()->getDepositTypes()->getDepositTypeById($id);
            if (!$depositType) {
                throw new NotFoundHttpException(Craft::t('container-deposits', 'Deposit type not found.'));
            }
        }

        $depositType ??= new DepositType();

        return $this->renderTemplate('container-deposits/deposit-types/_edit', [
            'depositType' => $depositType,
            'isNew' => !$depositType->id,
        ]);
    }

    /**
     * Saves a deposit type.
     *
     * @return Response|null The response, or null on a model failure.
     * @throws BadRequestHttpException if the request isn't a POST request.
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException if the posted ID doesn't resolve to a deposit type.
     * @throws Throwable if the deposit type can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requireAdmin(false);
        $this->requirePostRequest();

        $request = $this->request;
        $id = $request->getBodyParam('id');
        $service = ContainerDeposits::getInstance()->getDepositTypes();

        if ($id) {
            $depositType = $service->getDepositTypeById((int)$id);
            if (!$depositType) {
                throw new NotFoundHttpException(Craft::t('container-deposits', 'Deposit type not found.'));
            }
        } else {
            $depositType = new DepositType();
        }

        $depositType->name = $request->getBodyParam('name', $depositType->name);
        $depositType->handle = $request->getBodyParam('handle', $depositType->handle);

        // Checked before the cast, which would turn a blank or garbled amount into a valid 0.
        $amount = $request->getBodyParam('amount', $depositType->amount);
        $amountIsValid = is_numeric($amount);
        if ($amountIsValid) {
            $depositType->amount = (float)$amount;
        }

        if (!$amountIsValid || !$service->saveDepositType($depositType)) {
            if (!$amountIsValid) {
                $depositType->addError('amount', Craft::t('container-deposits', 'Enter the deposit amount as a number, e.g. 0.15.'));
            }

            return $this->asModelFailure(
                $depositType,
                Craft::t('container-deposits', 'Couldn\'t save deposit type.'),
                'depositType'
            );
        }

        Craft::$app->getSession()->setNotice(Craft::t('container-deposits', 'Deposit type saved.'));

        return $this->redirectToPostedUrl($depositType);
    }

    /**
     * Deletes a deposit type.
     *
     * @return Response A JSON success response.
     * @throws BadRequestHttpException if the request isn't a POST/JSON request.
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     * @throws Throwable
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionDelete(): Response
    {
        $this->requireAdmin(false);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');
        $service = ContainerDeposits::getInstance()->getDepositTypes();

        $usage = $service->getUsageCount($id);
        if ($usage > 0) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('container-deposits', 'This deposit type is still assigned to {count, plural, =1{# product or variant} other{# products and variants}}. Remove it from them first.', [
                    'count' => $usage,
                ]),
            ]);
        }

        if (!$service->deleteDepositTypeById($id)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('container-deposits', 'Could not delete deposit type.')]);
        }

        return $this->asJson(['success' => true]);
    }
}
