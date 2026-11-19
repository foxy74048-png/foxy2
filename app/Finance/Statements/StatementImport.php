<?php

namespace App\Finance\Statements;

use App\Finance\Accounts\Account;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um arquivo importado (extrato, fatura ou planilha antiga) numa conta. */
class StatementImport extends Model
{
    use BelongsToOrganization, HasUlids;

    public const FORMATS = ['ofx' => 'OFX', 'csv' => 'Planilha', 'xlsx' => 'Excel', 'pdf' => 'PDF'];

    protected $table = 'finance_statement_imports';

    protected $fillable = ['account_id', 'format', 'file_name', 'lines_count', 'new_lines_count', 'created_by', 'undone_at'];

    protected function casts(): array
    {
        return ['undone_at' => 'datetime', 'lines_count' => 'integer', 'new_lines_count' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class, 'import_id');
    }
}
