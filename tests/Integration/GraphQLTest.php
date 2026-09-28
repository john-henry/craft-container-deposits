<?php

/**
 * Covers the containerDeposit field added to Commerce's variant types: that
 * it gives a headless front end the same values the Twig variable gives a
 * template, and that it really is on the variant types a schema builds.
 */

use craft\commerce\Plugin as Commerce;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use johnhenry\containerdeposits\elements\DepositPurchasable;
use johnhenry\containerdeposits\fields\ContainerCountField;
use johnhenry\containerdeposits\fields\DepositTypeField;
use johnhenry\containerdeposits\gql\types\ContainerDeposit;
use johnhenry\containerdeposits\models\DepositType;

/**
 * A purchasable that reports a deposit type and container count through a
 * field layout of its own, so no product or field has to be saved.
 */
class GqlDepositPurchasable extends DepositPurchasable
{
    public ?DepositType $fakeDepositType = null;
    public int $fakeContainers = 1;

    public function getFieldLayout(): ?FieldLayout
    {
        $layout = new FieldLayout();
        $tab = new FieldLayoutTab();
        $tab->setLayout($layout);
        $tab->setElements([
            new CustomField(new DepositTypeField(['handle' => 'depositType'])),
            new CustomField(new ContainerCountField(['handle' => 'containersPerUnit'])),
        ]);
        $layout->setTabs([$tab]);

        return $layout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return match ($fieldHandle) {
            'depositType' => $this->fakeDepositType,
            'containersPerUnit' => $this->fakeContainers,
            default => null,
        };
    }
}

describe('ContainerDeposit::resolve()', function() {
    it('gives the tier, containers, unit deposit and display text', function() {
        $purchasable = new GqlDepositPurchasable();
        $purchasable->fakeDepositType = makeDepositType('15c', 'canDeposit', 0.15);
        $purchasable->fakeContainers = 6;

        $deposit = ContainerDeposit::resolve($purchasable);

        expect($deposit['type']->handle)->toBe('canDeposit')
            ->and($deposit['containers'])->toBe(6)
            ->and($deposit['unitAmount'])->toEqualWithDelta(0.90, 0.0001)
            ->and($deposit['display'])->toContain('Deposit');
    });

    it('is null for a purchasable with no deposit', function() {
        $purchasable = new GqlDepositPurchasable();

        expect(ContainerDeposit::resolve($purchasable))->toBeNull();
    });
});

describe('the containerDeposit field', function() {
    it('is on the variant interface and every variant type in a schema', function() {
        $scope = [];
        foreach (Commerce::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
            $scope[] = "productTypes.{$type->uid}:read";
        }

        // Craft caches a built schema by UID, so this one needs its own.
        $schema = new GqlSchema(['name' => 'Test', 'uid' => StringHelper::UUID(), 'scope' => $scope]);
        $def = Craft::$app->getGql()->getSchemaDef($schema, true);

        $variantTypes = array_filter(
            array_keys($def->getTypeMap()),
            static fn(string $name): bool => $name === 'VariantInterface' || str_ends_with($name, '_Variant'),
        );

        expect($variantTypes)->not->toBeEmpty();

        foreach ($variantTypes as $name) {
            expect($def->getType($name)->getFields())->toHaveKey(ContainerDeposit::FIELD);
        }
    });
});
