<?php

namespace App\Commerce\Fiscal;

use App\Commerce\Orders\Order;
use App\Platform\Files\File;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota fiscal de um pedido, emitida fora do Foxy. source: manual (XML ou chave digitada) ou o canal que trouxe.
 */
class FiscalDocument extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_fiscal_documents';

    protected $fillable = ['order_id', 'source', 'access_key', 'number', 'series', 'issued_at', 'total_cents', 'xml_file_id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'total_cents' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function xml(): BelongsTo
    {
        return $this->belongsTo(File::class, 'xml_file_id');
    }

    /** Chave em blocos de 4, como no DANFE. */
    public function formattedKey(): ?string
    {
        return $this->access_key ? trim(chunk_split($this->access_key, 4, ' ')) : null;
    }
}
