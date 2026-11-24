<?php

namespace App\Finance\WhatsApp;

use App\Finance\Access;
use App\Finance\Accounts\Account;
use App\Finance\Assistant\FinanceFox;
use App\Finance\Categories\Categorizer;
use App\Finance\Http\Middleware\ResolveWallet;
use App\Finance\Insights\Indicators;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionFilters;
use App\Finance\Transactions\TransactionService;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use App\Platform\Messaging\InboundHandler;
use App\Platform\Messaging\InboundMessage;
use App\Platform\Messaging\WhatsAppConnections;
use App\Platform\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Lançar pelo WhatsApp (docs/12 §8): "gastei 42,90 no mercado no débito" vira lançamento feito, com a fonte
 * "WhatsApp", na carteira padrão da pessoa ("no negócio" no texto muda a carteira). A raposa só lê a frase
 * (tarefa finance.parse_message); conta, categoria e o lançamento são do sistema, pelo TransactionService.
 * Também: *desfazer* (apaga o último em até 24 h), *resumo* (o mês, sem IA) e *ajuda*.
 */
class FinanceWhatsApp implements InboundHandler
{
    public function __construct(
        private WhatsAppConnections $whatsapp,
        private ContextStore $contexts,
        private FinanceFox $fox,
    ) {}

    public function handle(InboundMessage $message): void
    {
        $reply = fn (string $text) => $this->whatsapp->send($message->instance, $message->phone, $text);
        $link = PhoneLink::verified()->where('phone', $message->phone)->latest('verified_at')->first();

        if (! $link || ! $link->user) {
            // Número que não é de ninguém: um convite por dia, e mais nada.
            if (Cache::add('finance-wa-invite:'.$message->phone, true, now()->addDay())) {
                $reply("Oi! Este é o WhatsApp do *Foxy Finanças*. Para lançar seus gastos por aqui, crie sua conta em https://".config('foxy.hosts.financas')." e ligue este número em *Meu perfil*.");
            }

            return;
        }

        $limiter = 'finance-wa:'.$link->getKey();
        if (RateLimiter::tooManyAttempts($limiter, (int) config('finance.whatsapp.messages_per_hour', 30))) {
            return;
        }
        RateLimiter::hit($limiter, 3600);

        if ($message->kind !== InboundMessage::TEXT) {
            $reply('Por enquanto eu leio só mensagem escrita. Mande, por exemplo: *gastei 42,90 no mercado*.');

            return;
        }

        $reply($this->answer($link, $link->user, $message->text));
    }

    private function answer(PhoneLink $link, User $user, string $text): string
    {
        $command = Categorizer::normalize($text);
        if (in_array($command, ['ajuda', 'oi', 'ola', 'menu', 'help', 'comandos'], true)) {
            return self::help();
        }

        $choice = $this->wallet($link, $user, $command);
        if (! $choice) {
            return 'Não achei uma carteira sua no Foxy Finanças. Entre em https://'.config('foxy.hosts.financas').' para conferir.';
        }
        [$membership, $wallet] = $choice;
        $organization = $membership->organization;

        if (in_array($command, ['desfazer', 'corrigir', 'apagar', 'cancelar'], true)) {
            return $this->undo($link, $user);
        }

        return $this->contexts->run(ExecutionContext::forUser($organization, $user->getKey(), 'whatsapp'), function () use ($link, $user, $text, $command, $membership, $wallet, $organization) {
            if (in_array($command, ['resumo', 'saldo', 'mes', 'resumo do mes'], true)) {
                return $this->summary($wallet);
            }
            if (! Access::allows($membership, 'finance.transactions.manage')) {
                return "Na carteira *{$wallet->name}* você só pode ver. Peça a quem é dono(a) para mudar o seu papel.";
            }
            if (! FinanceFox::isReady()) {
                return 'O lançamento pelo WhatsApp ainda não está ligado. Tente mais tarde.';
            }

            $accounts = Account::active()->orderBy('name')->get();
            if ($accounts->isEmpty()) {
                return 'Cadastre uma conta ou cartão em *Contas e cartões* antes de lançar pelo WhatsApp.';
            }

            try {
                $parsed = $this->contexts->run(ExecutionContext::current()->asAiAgentFor((string) $user->getKey()),
                    fn () => $this->fox->parseMessage($organization, $text, $accounts->pluck('name')->all()));
            } catch (RuntimeException $e) {
                return $e->getMessage();
            } catch (Throwable $e) {
                report($e);

                return 'A raposa está indisponível agora. Tente de novo em alguns minutos.';
            }

            $cents = $parsed['amount'] !== null ? (int) round(abs($parsed['amount']) * 100) : 0;
            if (! $parsed['understood'] || ! $parsed['kind'] || $cents <= 0) {
                return "Não entendi o valor. Mande assim: *gastei 42,90 no mercado* ou *recebi 1500 do freela*.\nResponda *ajuda* para ver mais exemplos.";
            }

            $account = $this->account($accounts, $parsed['account']);
            $date = $this->date($parsed['date']);
            $description = Str::ucfirst($parsed['description'] ?: ($parsed['kind'] === Transaction::INCOME ? 'Recebimento' : 'Gasto'));
            $suggestion = app(Categorizer::class)->suggest($description, $parsed['kind']);

            try {
                $transaction = app(TransactionService::class)->create([
                    'kind' => $parsed['kind'], 'amount_cents' => $cents, 'date' => $date->format('Y-m-d'), 'description' => $description,
                    'account_id' => $account->id, 'category_id' => $suggestion['category']->id ?? null, 'source' => 'whatsapp',
                ])->first();
            } catch (ValidationException $e) {
                return 'Não deu para lançar: '.collect($e->errors())->flatten()->first();
            }

            $link->forceFill(['last_organization_id' => $organization->getKey(), 'last_transaction_id' => $transaction->id, 'last_at' => now()])->save();

            $when = $date->isToday() ? 'hoje' : ($date->isYesterday() ? 'ontem' : $date->format('d/m'));
            $what = $parsed['kind'] === Transaction::INCOME ? 'Recebimento' : 'Gasto';
            $category = $suggestion ? $suggestion['category']->fullName() : 'sem categoria (escolha no app)';
            $walletNote = count(ResolveWallet::membershipsOf($user->getKey())) > 1 ? "\nCarteira: {$wallet->name}" : '';

            return "✅ {$what} de *".Money::format($cents)."* · {$description} · {$account->name} · {$when}\nCategoria: {$category}{$walletNote}\nErrou? Responda *desfazer*.";
        });
    }

    /** @return array{0: Membership, 1: Wallet}|null a carteira citada no texto, a padrão escolhida, ou a pessoal */
    private function wallet(PhoneLink $link, User $user, string $command): ?array
    {
        $options = ResolveWallet::walletsOf(ResolveWallet::membershipsOf($user->getKey()))->filter(fn ($o) => $o['wallet'] !== null)->values();
        if ($options->isEmpty()) {
            return null;
        }

        $wantsBusiness = preg_match('/\b(no|do|pro|para o) (negocio|mei|cnpj)\b|\bda empresa\b/', $command) === 1;
        $wantsPersonal = preg_match('/\b(no|do|pro|para o) pessoal\b/', $command) === 1;
        $pick = match (true) {
            $wantsBusiness => $options->first(fn ($o) => $o['wallet']->isBusiness()),
            $wantsPersonal => $options->first(fn ($o) => ! $o['wallet']->isBusiness()),
            default => null,
        } ?? $options->first(fn ($o) => $o['membership']->organization_id === $link->default_organization_id)
          ?? $options->first(fn ($o) => ! $o['wallet']->isBusiness() && $o['membership']->role === 'owner')
          ?? $options->first();

        return [$pick['membership'], $pick['wallet']];
    }

    /** A conta citada (pelo nome); senão a mais usada nos últimos 60 dias que não é cartão; senão a primeira. */
    private function account($accounts, ?string $named): Account
    {
        if ($named && ($wanted = Categorizer::normalize($named)) !== '') {
            $found = $accounts->first(fn (Account $a) => Categorizer::normalize($a->name) === $wanted)
                ?? $accounts->first(fn (Account $a) => str_contains(Categorizer::normalize($a->name), $wanted) || str_contains($wanted, Categorizer::normalize($a->name)));
            if ($found) {
                return $found;
            }
            if (preg_match('/credito|cartao/', $wanted) && ($cards = $accounts->filter->isCard())->count() === 1) {
                return $cards->first();
            }
        }

        $usage = Transaction::query()->whereIn('account_id', $accounts->pluck('id'))->where('date', '>=', today()->subDays(60)->format('Y-m-d'))
            ->selectRaw('account_id, count(*) as uses')->groupBy('account_id')->pluck('uses', 'account_id');
        $notCards = $accounts->reject->isCard();

        return $notCards->sortByDesc(fn (Account $a) => (int) ($usage[$a->id] ?? 0))->first() ?? $accounts->first();
    }

    /** A data dita na mensagem, se fizer sentido (até 1 ano para trás, 60 dias para frente); senão hoje. */
    private function date(?string $date): Carbon
    {
        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $parsed = rescue(fn () => Carbon::createFromFormat('Y-m-d', $date)->startOfDay(), null, false);
            if ($parsed && $parsed->between(today()->subYear(), today()->addDays(60))) {
                return $parsed;
            }
        }

        return today();
    }

    private function undo(PhoneLink $link, User $user): string
    {
        if (! $link->last_transaction_id || ! $link->last_at || $link->last_at->lt(now()->subDay())) {
            return 'Não achei um lançamento recente feito pelo WhatsApp para desfazer. Para mudar um mais antigo, use o app.';
        }
        $membership = ResolveWallet::membershipsOf($user->getKey())->firstWhere('organization_id', $link->last_organization_id);
        if (! $membership) {
            return 'Você não está mais nessa carteira.';
        }

        return $this->contexts->run(ExecutionContext::forUser($membership->organization, $user->getKey(), 'whatsapp'), function () use ($link, $membership) {
            $transaction = Transaction::query()->where('source', 'whatsapp')->find($link->last_transaction_id);
            $link->forceFill(['last_transaction_id' => null])->save();
            if (! $transaction || ! Access::allows($membership, 'finance.transactions.manage')) {
                return 'Esse lançamento já não existe.';
            }
            $label = Money::format($transaction->amount_cents).' · '.$transaction->description;
            app(TransactionService::class)->delete($transaction);

            return "🗑️ Apaguei: {$label}.\nMande de novo do jeito certo, se quiser.";
        });
    }

    private function summary(Wallet $wallet): string
    {
        $month = today()->startOfMonth();
        $s = Indicators::for($month)->summary();

        return '📊 *'.Str::ucfirst(TransactionFilters::monthName($month))."* em {$wallet->name}\nEntrou: ".Money::format($s['income'])."\nSaiu: ".Money::format($s['expense'])."\nSobrou: ".Money::format($s['saved'])
            ."\nDetalhes em https://".config('foxy.hosts.financas').'/app/painel';
    }

    public static function help(): string
    {
        return "Sou a raposa do *Foxy Finanças* 🦊 Mande do seu jeito:\n"
            ."• *gastei 42,90 no mercado*\n• *paguei 120 de luz ontem no Nubank*\n• *recebi 1500 do freela*\n• *gastei 80 no posto no negócio* (vai para a carteira do negócio)\n\n"
            ."Também: *resumo* (o mês até agora) e *desfazer* (apaga o último lançado por aqui).";
    }
}
