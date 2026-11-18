<?php

namespace App\Finance;

use App\Platform\Integrations\Integration;

/**
 * O Foxy Finanças como sistema do Core: as carteiras são organizações desta integração (docs/12, A-F1).
 */
class Finance
{
    public const SLUG = 'financas';

    public const NAME = 'Foxy Finanças';

    public static function integration(): Integration
    {
        return Integration::firstOrCreate(['slug' => self::SLUG], ['name' => self::NAME]);
    }
}
