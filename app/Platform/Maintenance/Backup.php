<?php

namespace App\Platform\Maintenance;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Cópia de segurança diária (`foxy:backup`): banco (pg_dump compactado; no SQLite, cópia do arquivo) e os
 * arquivos enviados (storage/app/public e private), numa pasta fora do projeto (FOXY_BACKUP_PATH). Guarda
 * os últimos N dias. Se FOXY_BACKUP_DISK apontar para um disco (ex.: s3), manda uma cópia para lá também.
 * O resultado fica em last-backup.json, que a rotina de saúde confere.
 */
class Backup
{
    public function path(): string
    {
        return rtrim(config('foxy.backup.path'), '/\\');
    }

    /** @return array{ok: bool, at: string, files: array<int, array{name: string, bytes: int}>, error: ?string, offsite: ?string} */
    public function run(): array
    {
        $dir = $this->path();
        $stamp = now()->format('Ymd-His');
        $result = ['ok' => false, 'at' => now()->toIso8601String(), 'files' => [], 'error' => null, 'offsite' => null];

        try {
            if (! is_dir($dir) && ! @mkdir($dir, 0750, true)) {
                throw new RuntimeException("Não deu para criar a pasta {$dir}.");
            }

            $created = [$this->database("{$dir}/foxy-db-{$stamp}")];
            if ($files = $this->uploads("{$dir}/foxy-arquivos-{$stamp}.tar.gz")) {
                $created[] = $files;
            }
            $result['files'] = array_map(fn ($f) => ['name' => basename($f), 'bytes' => filesize($f)], $created);
            $result['ok'] = true;
            try {
                $result['offsite'] = $this->offsite($created);
            } catch (\Throwable $e) {
                // A cópia local vale; a de fora fica registrada como falha (aparece na rotina de saúde).
                $result['offsite'] = 'falhou ('.mb_substr($e->getMessage(), 0, 120).')';
            }
        } catch (\Throwable $e) {
            $result['error'] = mb_substr($e->getMessage(), 0, 500);
        }

        $this->prune();
        if (is_dir($dir)) {
            file_put_contents("{$dir}/last-backup.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $result;
    }

    /** Último resultado (null se nunca rodou). */
    public function last(): ?array
    {
        $file = $this->path().'/last-backup.json';

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    /** Apaga cópias mais velhas que o prazo. */
    public function prune(): int
    {
        $limit = now()->subDays((int) config('foxy.backup.keep_days'))->getTimestamp();
        $removed = 0;
        foreach (glob($this->path().'/foxy-*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $limit) {
                @unlink($file) && $removed++;
            }
        }

        return $removed;
    }

    private function database(string $base): string
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        if (($db['driver'] ?? null) === 'pgsql') {
            $file = "{$base}.dump";
            $process = new Process(
                ['pg_dump', '-h', (string) $db['host'], '-p', (string) $db['port'], '-U', (string) $db['username'], '-d', (string) $db['database'], '-Fc', '-f', $file],
                null, ['PGPASSWORD' => (string) $db['password']], null, 600,
            );
            $process->run();
            if (! $process->isSuccessful()) {
                @unlink($file);
                throw new RuntimeException('pg_dump falhou: '.trim($process->getErrorOutput()));
            }

            return $file;
        }

        if (($db['driver'] ?? null) === 'sqlite' && is_file((string) $db['database'])) {
            $file = "{$base}.sqlite";
            copy($db['database'], $file);

            return $file;
        }

        throw new RuntimeException('Banco sem cópia automática (driver '.($db['driver'] ?? '?').').');
    }

    private function uploads(string $file): ?string
    {
        $root = storage_path('app');
        $folders = array_values(array_filter(['public', 'private'], fn ($f) => is_dir("{$root}/{$f}")));
        if ($folders === []) {
            return null;
        }

        // A própria pasta de backup pode estar dentro de storage (padrão local): fica de fora.
        $exclude = str_starts_with($this->path(), $root) ? ['--exclude='.ltrim(substr($this->path(), strlen($root)), '/\\')] : [];
        // Nome relativo (roda dentro da pasta do backup): o tar do Windows confunde "C:" com outro computador.
        $process = new Process(['tar', '-czf', basename($file), ...$exclude, '-C', $root, ...$folders], dirname($file), null, null, 900);
        $process->run();
        if (! $process->isSuccessful()) {
            @unlink($file);
            throw new RuntimeException('tar falhou: '.trim($process->getErrorOutput()));
        }

        return $file;
    }

    private function offsite(array $files): ?string
    {
        $disk = config('foxy.backup.disk');
        if (! $disk) {
            return null;
        }

        foreach ($files as $file) {
            $stream = fopen($file, 'r');
            Storage::disk($disk)->writeStream('backups/'.basename($file), $stream);
            is_resource($stream) && fclose($stream);
        }

        return $disk;
    }
}
