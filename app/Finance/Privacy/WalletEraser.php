<?php

namespace App\Finance\Privacy;

use App\Finance\Accounts\AccountSync;
use App\Platform\Organizations\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Apagar uma carteira de vez (fatia 11, LGPD): todos os lançamentos, contas, categorias, extratos, metas,
 * quem me deve, grupos, sócios, conquistas, as dicas da raposa, as pessoas e os convites. Não tem volta.
 *
 * Fica só a casca da organização (nome "Carteira apagada", situação `deleted`), porque o registro de
 * auditoria e o uso de créditos apontam para ela — nenhum dos dois guarda valores ou descrições.
 * O que outras carteiras tinham ligado a esta é solto, não apagado: a retirada espelhada na carteira
 * pessoal do sócio continua lá (é dele), só perde a ligação.
 */
class WalletEraser
{
    public const DELETED = 'deleted';

    /** Filhos antes dos pais (as chaves estrangeiras não deixam o contrário). */
    private const TABLES = [
        'finance_group_shares', 'finance_group_payments', 'finance_group_expenses', 'finance_group_members', 'finance_groups',
        'finance_person_entries', 'finance_contacts',
        'finance_partner_settlements',
        'finance_goal_entries', 'finance_goals',
        'finance_challenges', 'finance_rewards', 'finance_ai_notes',
        'finance_budgets', 'finance_category_rules',
        'finance_statement_lines', 'finance_transactions', 'finance_statement_imports',
        'finance_partners', 'finance_accounts', 'finance_categories',
        'finance_wallets',
        'invitations', 'memberships',
    ];

    public function erase(Organization $organization): void
    {
        $id = $organization->getKey();

        DB::transaction(function () use ($id, $organization) {
            $transactionIds = DB::table('finance_transactions')->where('organization_id', $id)->pluck('id');

            // Ligações vindas de outras carteiras: soltar.
            $transactionIds->chunk(500)->each(fn ($chunk) => DB::table('finance_transactions')
                ->where('organization_id', '!=', $id)->whereIn('linked_transaction_id', $chunk->all())
                ->update(['linked_transaction_id' => null]));
            DB::table('finance_partners')->where('organization_id', '!=', $id)->where('mirror_organization_id', $id)
                ->update(['mirror_organization_id' => null, 'mirror_account_id' => null]);
            DB::table('finance_whatsapp_links')->where('default_organization_id', $id)->update(['default_organization_id' => null]);
            DB::table('finance_whatsapp_links')->where('last_organization_id', $id)
                ->update(['last_organization_id' => null, 'last_transaction_id' => null]);

            foreach (self::TABLES as $table) {
                DB::table($table)->where('organization_id', $id)->delete();
            }
            // Conversas com a raposa nesta carteira (as mensagens vão junto, em cascata).
            DB::table('conversations')->where('organization_id', $id)->delete();

            $organization->forceFill(['name' => 'Carteira apagada', 'status' => self::DELETED])->save();
        });

        AccountSync::sync($organization->fresh());
    }
}
