<?php

namespace App\Commerce;

use App\Platform\Integrations\Integration;

/**
 * O Foxy Commerce como sistema do Core: as lojas são organizações desta integração (docs/09, F2).
 */
class Commerce
{
    public const SLUG = 'commerce';

    public static function integration(): Integration
    {
        return Integration::firstOrCreate(['slug' => self::SLUG], ['name' => 'Foxy Commerce']);
    }
}
