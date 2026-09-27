<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\containerdeposits\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The GraphQL type for a deposit type: one tier, such as 15c on a can.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.1.0
 */
class ContainerDepositType
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string The GraphQL type name.
     */
    public const NAME = 'ContainerDepositType';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns the type, creating it the first time it's asked for.
     *
     * @return ObjectType The deposit type's GraphQL type.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => 'A container deposit tier.',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'handle' => Type::string(),
                'amount' => [
                    'type' => Type::float(),
                    'description' => 'The deposit on one container, in the store currency.',
                ],
            ],
        ]));
    }
}
