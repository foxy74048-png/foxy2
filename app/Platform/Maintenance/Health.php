<?php

namespace App\Platform\Maintenance;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Rotina de saúde (`foxy:saude`, a cada 10 minutos): banco, disco, backup do dia, fila, contas de
 * marketplace com problema e certificados dos endereços. O resultado vai para storage/app/private/health.json
 * (mostrado no Foxy Admin). Quando algo passa a dar problema, a equipe da Foxy (is_staff) recebe e-mail; se
 * continuar, um lembrete por dia; e outro quando voltar ao normal.
 */
class Health
{
    public const OK = 'ok';

    public const WARN = 'atencao';

    public const FAIL = 'falha';

    public function __construct(private Backup $backup) {}

    public static function file(): string
    {
        return storage_path('app/private/health.json');
    }

    public static function latest(): ?array
    {
        return is_file(self::file()) ? json_decode((string) file_get_contents(self::file()), true) : null;
    }

    /** Roda as verificações, grava e avisa. */
    public function run(bool $notify = true): array
    {
        $checks = array_map(fn ($check) => $check + ['detail' => ''], [
            $this->database(),
            $this->disk(),
            $this->lastBackup(),
            $this->queue(),
            $this->channels(),
            ...$this->certificates(),
        ]);

        $previous = self::latest();
        $report = [
            'checked_at' => now()->toIso8601String(),
            'status' => collect($checks)->contains('status', self::FAIL) ? self::FAIL : (collect($checks)->contains('status', self::WARN) ? self::WARN : self::OK),
            'checks' => $checks,
            'alerted_at' => $previous['alerted_at'] ?? null,
        ];

        if ($notify) {
            $report['alerted_at'] = $this->alert($report, $previous);
        }

        @mkdir(dirname(self::file()), 0750, true);
        file_put_contents(self::file(), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $report;
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['key' => 'banco', 'label' => 'Banco de dados', 'status' => self::OK, 'detail' => 'respondendo'];
        } catch (\Throwable $e) {
            return ['key' => 'banco', 'label' => 'Banco de dados', 'status' => self::FAIL, 'detail' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function disk(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());
        if (! $free || ! $total) {
            return ['key' => 'disco', 'label' => 'Espaço em disco', 'status' => self::WARN, 'detail' => 'não deu para medir'];
        }
        $pct = $free / $total * 100;
        $gb = $free / 1024 ** 3;
        $status = $pct < 5 || $gb < 1 ? self::FAIL : ($pct < 15 || $gb < 3 ? self::WARN : self::OK);

        return ['key' => 'disco', 'label' => 'Espaço em disco', 'status' => $status, 'detail' => number_format($gb, 1, ',', '.').' GB livres ('.number_format($pct, 0).'%)'];
    }

    private function lastBackup(): array
    {
        $last = $this->backup->last();
        if (! $last) {
            return ['key' => 'backup', 'label' => 'Cópia de segurança', 'status' => self::WARN, 'detail' => 'ainda não rodou'];
        }
        $at = Carbon::parse($last['at']);
        $when = $at->timezone('America/Sao_Paulo')->format('d/m H:i');
        if (! $last['ok']) {
            return ['key' => 'backup', 'label' => 'Cópia de segurança', 'status' => self::FAIL, 'detail' => "falhou em {$when}: {$last['error']}"];
        }
        $size = array_sum(array_column($last['files'], 'bytes'));
        if (str_starts_with((string) $last['offsite'], 'falhou')) {
            return ['key' => 'backup', 'label' => 'Cópia de segurança', 'status' => self::WARN, 'detail' => "{$when} · cópia local feita, a de fora do servidor {$last['offsite']}"];
        }

        return ['key' => 'backup', 'label' => 'Cópia de segurança', 'status' => $at->lt(now()->subHours(26)) ? self::FAIL : self::OK,
            'detail' => "{$when} · ".number_format($size / 1024 ** 2, 1, ',', '.').' MB'.($last['offsite'] ? ' · cópia em '.$last['offsite'] : ' · só no servidor')];
    }

    private function queue(): array
    {
        if (! Schema::hasTable('jobs')) {
            return ['key' => 'fila', 'label' => 'Fila de tarefas', 'status' => self::OK, 'detail' => 'sem fila no banco'];
        }
        $stuck = DB::table('jobs')->where('created_at', '<', now()->subMinutes(15)->getTimestamp())->count();
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
        $status = $stuck > 0 ? self::FAIL : ($failed > 0 ? self::WARN : self::OK);

        return ['key' => 'fila', 'label' => 'Fila de tarefas', 'status' => $status,
            'detail' => ($stuck ? "{$stuck} parada(s) há mais de 15 min · " : '').($failed ? "{$failed} falha(s) nas últimas 24 h" : 'em dia')];
    }

    private function channels(): array
    {
        if (! Schema::hasTable('commerce_channel_connections')) {
            return ['key' => 'canais', 'label' => 'Contas de marketplace', 'status' => self::OK, 'detail' => '—'];
        }
        $problems = DB::table('commerce_channel_connections')->where('status', 'needs_attention')->count();

        return ['key' => 'canais', 'label' => 'Contas de marketplace', 'status' => $problems ? self::WARN : self::OK,
            'detail' => $problems ? "{$problems} conta(s) de loja pedindo para reconectar" : 'todas conectadas'];
    }

    /** Validade do certificado HTTPS de cada endereço (avisa 14 dias antes). */
    private function certificates(): array
    {
        $checks = [];
        foreach (config('foxy.health.ssl_hosts') as $host) {
            $days = $this->certificateDays($host);
            $checks[] = ['key' => "ssl:{$host}", 'label' => "Certificado {$host}",
                'status' => $days === null ? self::WARN : ($days < 7 ? self::FAIL : ($days < 14 ? self::WARN : self::OK)),
                'detail' => $days === null ? 'não deu para ler' : "vence em {$days} dias"];
        }

        return $checks;
    }

    private function certificateDays(string $host): ?int
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $client = @stream_socket_client("ssl://{$host}:443", $errno, $error, 8, STREAM_CLIENT_CONNECT, $context);
        if (! $client) {
            return null;
        }
        $cert = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);
        $info = $cert ? openssl_x509_parse($cert) : null;

        return isset($info['validTo_time_t']) ? (int) floor(($info['validTo_time_t'] - time()) / 86400) : null;
    }

    /** E-mail quando piora, um lembrete por dia enquanto houver falha, e quando volta ao normal. */
    private function alert(array $report, ?array $previous): ?string
    {
        $bad = fn (?array $r) => collect($r['checks'] ?? [])->where('status', '!=', self::OK)->pluck('status', 'key')->all();
        $now = $bad($report);
        $before = $bad($previous);
        $worse = collect($now)->contains(fn ($status, $key) => ! isset($before[$key]) || ($status === self::FAIL && $before[$key] !== self::FAIL));
        $reminder = $now !== [] && $report['alerted_at'] && now()->diffInHours($report['alerted_at']) >= 24 && collect($now)->contains(self::FAIL);
        $recovered = $now === [] && $before !== [] && $previous !== null;

        if (! $worse && ! $reminder && ! $recovered) {
            return $report['alerted_at'];
        }

        $emails = config('foxy.health.alert_emails') ?: User::where('is_staff', true)->pluck('email')->all();
        if ($emails !== []) {
            Notification::route('mail', $emails)->notify(new HealthAlertNotification($report, $recovered));
        }

        return $recovered ? null : now()->toIso8601String();
    }
}
