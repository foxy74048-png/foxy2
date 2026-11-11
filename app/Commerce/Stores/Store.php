<?php

namespace App\Commerce\Stores;

use App\Platform\Files\File;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A loja (1:1 com a organização). A loja própria pública acha a loja pelo slug, sem usuário logado:
 * nesse caso tirar o escopo explicitamente e montar o contexto da loja achada (docs/09, seção 7).
 */
class Store extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_stores';

    protected $fillable = [
        'organization_id', 'slug', 'name', 'whatsapp', 'published_at', 'pix_key', 'pix_name', 'pix_city', 'payment_instructions',
        'delivery', 'primary_color', 'logo_file_id', 'about', 'whatsapp_settings', 'mode', 'hide_sold_out',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'delivery' => 'array', 'whatsapp_settings' => 'array', 'hide_sold_out' => 'boolean'];
    }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(File::class, 'logo_file_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** Modo catálogo: sem carrinho nem Pix; a cliente pede pelo WhatsApp da loja. */
    public function isCatalog(): bool
    {
        return $this->mode === 'catalogo';
    }

    /** O que falta para publicar (vazio = pode publicar). */
    public function publishProblem(): ?string
    {
        if ($this->isCatalog()) {
            return preg_replace('/\D/', '', (string) $this->whatsapp) === '' ? 'Para publicar o catálogo, preencha o WhatsApp da loja: é para ele que as clientes mandam o pedido.' : null;
        }

        return ! $this->pixReady() || $this->deliveryOptions() === []
            ? 'Para publicar a loja com carrinho, preencha o Pix (chave, nome e cidade do recebedor) e ligue pelo menos uma forma de entrega. Ou escolha "Só catálogo": aí basta o WhatsApp.'
            : null;
    }

    public function pixReady(): bool
    {
        return filled($this->pix_key) && filled($this->pix_name) && filled($this->pix_city);
    }

    /**
     * Formas de entrega ligadas: chave => [nome, taxa em centavos, detalhe].
     *
     * @return array<string, array{label: string, fee_cents: int, detail: ?string}>
     */
    public function deliveryOptions(): array
    {
        $d = $this->delivery ?? [];
        $options = [];
        if (! empty($d['pickup'])) {
            $options['pickup'] = ['label' => 'Retirar na loja', 'fee_cents' => 0, 'detail' => $d['pickup_address'] ?? null];
        }
        if (! empty($d['local'])) {
            $options['local'] = ['label' => 'Entrega local', 'fee_cents' => (int) ($d['local_fee_cents'] ?? 0), 'detail' => $d['local_area'] ?? null];
        }
        if (! empty($d['shipping'])) {
            $options['shipping'] = ['label' => 'Envio pelos Correios/transportadora', 'fee_cents' => (int) ($d['shipping_fee_cents'] ?? 0), 'detail' => $d['shipping_note'] ?? null];
        }

        return $options;
    }

    public function color(): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->primary_color) ? $this->primary_color : '#5b3fd1';
    }

    public function whatsappLink(string $text): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->whatsapp);
        if ($digits === '') {
            return null;
        }

        return 'https://wa.me/'.(strlen($digits) <= 11 ? '55'.$digits : $digits).'?text='.rawurlencode($text);
    }

    /**
     * Endereço livre a partir do nome: sem acento, sem palavra reservada, com -2, -3... se já existir.
     */
    public static function availableSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 50, '') ?: 'loja';

        if (in_array($base, config('commerce.reserved_slugs'), true)) {
            $base .= '-loja';
        }

        $slug = $base;
        for ($i = 2; static::withoutGlobalScopes()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
