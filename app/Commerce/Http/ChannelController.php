<?php

namespace App\Commerce\Http;

use App\Commerce\Billing\Plans;
use App\Commerce\Channels\ChannelConnection;
use App\Commerce\Channels\ChannelError;
use App\Commerce\Channels\Channels;
use App\Commerce\Channels\InboxItem;
use App\Commerce\Channels\ProcessInboxItem;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Conectar a conta do lojista em cada marketplace (OAuth do canal) e receber os avisos dos canais.
 */
class ChannelController
{
    public function index(): View
    {
        return view('commerce.channels.index', [
            'channels' => Channels::all(),
            'connections' => ChannelConnection::withCount('listings')->orderBy('channel')->get()->groupBy('channel'),
            'limit' => Plans::limit(ExecutionContext::current()->organization, 'channels'),
        ]);
    }

    public function connect(Request $request, string $channel): RedirectResponse
    {
        abort_unless(Channels::available($channel), 404);

        $limit = Plans::limit(ExecutionContext::current()->organization, 'channels');
        if ($limit !== null && ChannelConnection::where('status', '!=', 'disconnected')->count() >= $limit) {
            return back()->withErrors(['channel' => "O seu plano permite {$limit} conta(s) de marketplace conectada(s). Para mais, mude de plano em Assinatura."]);
        }

        // O "state" volta do canal e precisa bater com a sessão (impede que alguém conecte a conta dele na sua loja).
        $state = Str::random(40);
        $request->session()->put("commerce.oauth.{$channel}", [$state, ExecutionContext::current()->organization->getKey()]);

        return redirect()->away(Channels::connector($channel)->authorizationUrl($state, Channels::redirectUri($channel)));
    }

    public function callback(Request $request, string $channel): RedirectResponse
    {
        abort_unless(Channels::available($channel), 404);
        [$state, $organizationId] = $request->session()->pull("commerce.oauth.{$channel}", [null, null]);

        if (! $state || ! hash_equals($state, (string) $request->query('state')) || $organizationId !== ExecutionContext::current()->organization->getKey()) {
            return redirect()->route('commerce.channels')->withErrors(['channel' => 'A autorização expirou ou não começou aqui. Tente conectar de novo.']);
        }
        if (blank($request->query('code'))) {
            return redirect()->route('commerce.channels')->withErrors(['channel' => 'A autorização foi recusada no '.Channels::all()[$channel]['label'].'.']);
        }

        try {
            $account = Channels::connector($channel)->connect((string) $request->query('code'), Channels::redirectUri($channel));
        } catch (ChannelError $e) {
            return redirect()->route('commerce.channels')->withErrors(['channel' => $e->getMessage()]);
        }

        // Uma conta de vendedor fica numa loja só (evita pedido importado em duas lojas).
        $other = ChannelConnection::withoutGlobalScopes()->where('channel', $channel)->where('external_account_id', $account['external_account_id'])
            ->where('organization_id', '!=', $organizationId)->where('status', '!=', 'disconnected')->exists();
        if ($other) {
            return redirect()->route('commerce.channels')->withErrors(['channel' => 'Esta conta já está conectada a outra loja no Foxy Commerce.']);
        }

        $connection = ChannelConnection::withoutGlobalScopes()->updateOrCreate(
            ['channel' => $channel, 'external_account_id' => $account['external_account_id']],
            ['organization_id' => $organizationId, 'name' => $account['name'], 'access_token' => $account['access_token'], 'refresh_token' => $account['refresh_token'],
                'token_expires_at' => $account['expires_at'], 'status' => 'active', 'last_error' => null],
        );
        Audit::record('commerce.channel.connected', subject: $connection, metadata: ['channel' => $channel]);

        return redirect()->route('commerce.channels')->with('status', $connection->label().' conectado: '.($connection->name ?? $connection->external_account_id).'.');
    }

    public function disconnect(ChannelConnection $connection): RedirectResponse
    {
        $connection->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null]);
        $connection->listings()->whereIn('status', ['active', 'publishing'])->update(['status' => 'paused']);
        Audit::record('commerce.channel.disconnected', subject: $connection);

        return back()->with('status', $connection->label().' desconectado. Os anúncios ficam pausados no Foxy (no canal, continuam como estão).');
    }

    /** Aviso do canal (público): guarda e responde na hora; o processamento é em fila. */
    public function webhook(Request $request, string $channel): Response
    {
        abort_unless(Channels::available($channel), 404);
        $notice = Channels::connector($channel)->parseNotification($request);

        if ($notice) {
            $item = InboxItem::create([
                'channel' => $channel, 'external_account_id' => $notice['external_account_id'], 'topic' => mb_substr($notice['topic'], 0, 60),
                'resource' => mb_substr($notice['resource'], 0, 255), 'received_at' => now(),
            ]);
            ProcessInboxItem::dispatch($item->id);
        }

        return response('ok', 200);
    }
}
