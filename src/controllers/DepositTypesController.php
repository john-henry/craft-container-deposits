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
 * saving, deleting, and reordering. All actions require an admin account.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class DepositTypesController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Renders the deposit types index.
     *
     * @return Response The rendering result.
     * @throws ForbiddenHttpException if the user is not an admin.
     * @throws InvalidConfigException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        $depositTypes = ContainerDeposits::getInstance()->depositTypes->getAllDepositTypes();

        return $this->renderTemplate('container-deposits/deposit-types/index', [
            'depositTypes' => $depositTypes,
        ]);
    }

    /**
     * Renders the deposit type edit screen.
     *
     * @param int|null $id The deposit type ID, or null to create a new one.
     * @return Response The rendering result.
     * @throws ForbiddenHttpException if the user is not an admin.
     * @throws NotFoundHttpException if the deposit type can't be found.
     * @throws InvalidConfigException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionEdit(?int $id = null): Response
    {
        $this->requireAdmin(false);

        if ($id) {
            $depositType = ContainerDeposits::getInstance()->depositTypes->getDepositTypeById($id);
            if (!$depositType) {
                throw new NotFoundHttpException(Craft::t('container-deposits', 'Deposit type not found.'));
            }
        } else {
            $depositType = new DepositType();
        }

        return $this->renderTemplate('container-deposits/deposit-types/edit', [
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $id = $request->getBodyParam('id');
        $service = ContainerDeposits::getInstance()->depositTypes;

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
        $depositType->amount = (float)$request->getBodyParam('amount', $depositType->amount);

        if (!$service->saveDepositType($depositType)) {
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionDelete(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = Craft::$app->getRequest()->getRequiredBodyParam('id');

        if (!ContainerDeposits::getInstance()->depositTypes->deleteDepositTypeById((int)$id)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('container-deposits', 'Could not delete deposit type.')]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Reorders the deposit types.
     *
     * @return Response A JSON success response.
     * @throws BadRequestHttpException if the request isn't a POST/JSON request.
     * @throws Throwable if a deposit type can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionReorder(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        $service = ContainerDeposits::getInstance()->depositTypes;

        foreach ($ids as $sortOrder => $id) {
            $type = $service->getDepositTypeById((int)$id);
            if ($type) {
                $type->sortOrder = $sortOrder + 1;
                $service->saveDepositType($type);
            }
        }

        return $this->asJson(['success' => true]);
    }
}
