<?php

namespace App\Finance\Debts;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pessoa com quem há dinheiro ou objetos emprestados (amigo, família). Não é login: é só um nome na carteira. */
class Contact extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'finance_contacts';

    protected $fillable = ['name', 'phone', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PersonEntry::class);
    }

    /** Positivo: te deve. Negativo: você deve. (Usa a soma carregada com withSum, se houver.) */
    public function balanceCents(): int
    {
        return (int) ($this->entries_sum_amount_cents ?? $this->entries()->sum('amount_cents'));
    }

    /** Link do WhatsApp (abre o app da própria pessoa com o texto pronto); null sem telefone. */
    public function whatsappLink(string $text): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);
        if (strlen($digits) < 10) {
            return null;
        }
        if (strlen($digits) <= 11) {
            $digits = '55'.$digits;
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($text);
    }
}
