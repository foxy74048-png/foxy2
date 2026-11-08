<?php

namespace App\Commerce\Http;

use App\Commerce\Catalog\Money;
use App\Commerce\Fiscal\FiscalDocument;
use App\Commerce\Fiscal\NfeXml;
use App\Commerce\Orders\Order;
use App\Platform\Audit\Audit;
use App\Platform\Files\File;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Nota fiscal do pedido emitida fora do Foxy: XML anexado ou chave digitada. O XML fica em disco privado
 * (tem dados do comprador) e só sai pela rota autenticada.
 */
class FiscalController
{
    public function store(Request $request, Order $order): RedirectResponse
    {
        $request->validate([
            'xml' => ['nullable', 'file', 'max:2048', 'mimetypes:text/xml,application/xml,text/plain'],
            'access_key' => ['nullable', 'string', 'max:60'],
            'number' => ['nullable', 'string', 'max:20'],
        ], [], ['xml' => 'arquivo XML', 'access_key' => 'chave de acesso']);

        if ($request->hasFile('xml')) {
            $data = NfeXml::parse((string) file_get_contents($request->file('xml')->getRealPath()));
        } else {
            $key = NfeXml::normalizeKey($request->input('access_key'));
            if (! NfeXml::validKey($key)) {
                throw ValidationException::withMessages(['access_key' => 'Chave inválida: são 44 números e o último confere os outros. Copie do DANFE ou do XML.']);
            }
            $data = ['access_key' => $key, 'number' => $request->input('number') ?: ltrim(substr($key, 25, 9), '0'), 'series' => ltrim(substr($key, 22, 3), '0') ?: '0', 'issued_at' => null, 'total_cents' => null];
        }

        if (FiscalDocument::where('access_key', $data['access_key'])->exists()) {
            throw ValidationException::withMessages(['access_key' => 'Esta nota já está ligada a um pedido da loja.']);
        }

        $document = DB::transaction(function () use ($request, $order, $data) {
            $file = $request->hasFile('xml') ? File::store($request->file('xml'), 'commerce.nfe_xml', 'local', 'commerce/nfe') : null;

            return FiscalDocument::create($data + ['order_id' => $order->id, 'source' => 'manual', 'xml_file_id' => $file?->id]);
        });
        Audit::record('commerce.fiscal.attached', subject: $document, metadata: ['order' => $order->number]);

        $warning = $data['total_cents'] !== null && $data['total_cents'] !== $order->total_cents
            ? ' Atenção: o valor da nota ('.Money::format($data['total_cents']).') é diferente do total do pedido.' : '';

        return back()->with('status', 'Nota '.$document->number.' ligada ao pedido.'.$warning);
    }

    public function download(Order $order, string $document): StreamedResponse
    {
        $document = FiscalDocument::where('order_id', $order->id)->whereNotNull('xml_file_id')->with('xml')->findOrFail($document);

        return Storage::disk($document->xml->disk)->download($document->xml->path, 'NFe'.$document->access_key.'.xml');
    }

    public function destroy(Order $order, string $document): RedirectResponse
    {
        $document = FiscalDocument::where('order_id', $order->id)->findOrFail($document);
        DB::transaction(function () use ($document) {
            $file = $document->xml;
            $document->delete();
            $file?->purge();
        });
        Audit::record('commerce.fiscal.removed', subject: $document);

        return back()->with('status', 'Nota desligada do pedido.');
    }
}
