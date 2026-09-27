<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\gql\types;

use Craft;
use craft\commerce\base\Purchasable;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\variables\ContainerDepositsVariable;

/**
 * The deposit a purchasable carries, added to Commerce's variant types.
 *
 * The values are the ones the Twig variable gives a template: the deposit
 * type, how many containers are in a unit, the deposit on one unit, and the
 * "+ 15c Deposit" text shown beside the price.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.1.0
 */
class ContainerDeposit
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string The GraphQL type name.
     */
    public const NAME = 'ContainerDeposit';

    /**
     * @var string The field added to variant types.
     */
    public const FIELD = 'containerDeposit';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns the type, creating it the first time it's asked for.
     *
     * @return ObjectType The deposit's GraphQL type.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => 'The container deposit charged on a purchasable.',
            'fields' => [
                'type' => [
                    'type' => ContainerDepositType::getType(),
                    'description' => 'The deposit tier.',
                ],
                'containers' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'How many containers are in one unit, such as 6 for a six-pack.',
                ],
                'unitAmount' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'The deposit on one unit: the tier amount times the containers.',
                ],
                'unitAmountAsCurrency' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The unit deposit formatted in the store currency, as shown in the cart.',
                ],
                'display' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The text shown beside the price, such as "+ 15c Deposit".',
                ],
            ],
        ]));
    }

    /**
     * Returns the field definition added to each variant type.
     *
     * @return array The field definition.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public static function fieldDefinition(): array
    {
        return [
            'name' => self::FIELD,
            'type' => self::getType(),
            'description' => 'The container deposit charged on this variant, or null if it has none.',
            'resolve' => static fn(mixed $source): ?array => $source instanceof Purchasable ? self::resolve($source) : null,
        ];
    }

    /**
     * Works out a purchasable's deposit.
     *
     * @param Purchasable $purchasable The variant or other purchasable.
     * @return array|null The deposit's values, or null if it carries none.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public static function resolve(Purchasable $purchasable): ?array
    {
        $cart = ContainerDeposits::getInstance()->getDepositCart();
        $type = $cart->getDepositTypeForPurchasable($purchasable);

        if ($type === null) {
            return null;
        }

        $containers = $cart->getContainerCountForPurchasable($purchasable);
        $unitAmount = (float)$type->amount * $containers;
        $currency = $purchasable->getStore()->getCurrency()?->getCode();
        $formatted = (new ContainerDepositsVariable())->formatAmount($unitAmount, $currency);

        return [
            'type' => $type,
            'containers' => $containers,
            'unitAmount' => $unitAmount,
            'unitAmountAsCurrency' => $formatted,
            'display' => $unitAmount > 0 ? Craft::t('container-deposits', '+ {amount} Deposit', ['amount' => $formatted]) : '',
        ];
    }
}
