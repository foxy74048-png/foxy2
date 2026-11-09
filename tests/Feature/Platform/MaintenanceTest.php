<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Platform\Maintenance\Backup;
use App\Platform\Maintenance\Health;
use App\Platform\Maintenance\HealthAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cópia de segurança diária e rotina de saúde (avisos por e-mail e quadro no Foxy Admin).
 */
class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/foxy-backup-test-'.uniqid();
        $db = $this->dir.'-banco.sqlite';
        file_put_contents($db, 'banco de teste');
        config(['foxy.backup.path' => $this->dir, 'foxy.backup.keep_days' => 14, 'foxy.health.ssl_hosts' => [], 'foxy.health.alert_emails' => [],
            'database.connections.sqlite.database' => $db]);
        @unlink(Health::file());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        @unlink($this->dir.'-banco.sqlite');
        @unlink(Health::file());
        parent::tearDown();
    }

    public function test_backup_copia_banco_e_arquivos_e_apaga_os_antigos(): void
    {
        mkdir($this->dir, 0750, true);
        touch($old = $this->dir.'/foxy-db-20200101-000000.sqlite', now()->subDays(20)->getTimestamp());
        touch($recent = $this->dir.'/foxy-db-ontem.sqlite', now()->subDay()->getTimestamp());

        $this->artisan('foxy:backup')->assertSuccessful();

        $last = app(Backup::class)->last();
        $this->assertTrue($last['ok'], (string) $last['error']);
        $names = array_column($last['files'], 'name');
        $this->assertMatchesRegularExpression('/^foxy-db-\d{8}-\d{6}\.sqlite$/', $names[0]);
        $this->assertSame('banco de teste', file_get_contents($this->dir.'/'.$names[0]));
        $this->assertStringEndsWith('.tar.gz', $names[1] ?? '');
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function test_saude_avisa_a_equipe_quando_piora_e_quando_volta(): void
    {
        Notification::fake();
        $staff = User::factory()->staff()->create(['email' => 'equipe@foxy.net.br']);
        User::factory()->create(['email' => 'cliente@loja.test']);

        // Sem backup ainda: atenção e um e-mail para a equipe (não para clientes).
        $report = app(Health::class)->run();
        $this->assertSame(Health::WARN, collect($report['checks'])->firstWhere('key', 'backup')['status']);
        $this->assertSame(Health::OK, collect($report['checks'])->firstWhere('key', 'banco')['status']);
        Notification::assertSentOnDemand(HealthAlertNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === ['equipe@foxy.net.br'] && ! $n->recovered);

        // Mesma situação de novo: não repete o e-mail.
        app(Health::class)->run();
        Notification::assertSentOnDemandTimes(HealthAlertNotification::class, 1);

        // Backup feito: avisa que voltou ao normal (se o resto do servidor estiver bem).
        app(Backup::class)->run();
        $report = app(Health::class)->run();
        if ($report['status'] === Health::OK) {
            Notification::assertSentOnDemand(HealthAlertNotification::class, fn ($n) => $n->recovered);
        }

        // Backup que falha vira falha na hora.
        config(['database.connections.sqlite.database' => ':memory:']);
        $this->artisan('foxy:backup')->assertFailed();
        $this->assertSame(Health::FAIL, collect(Health::latest()['checks'])->firstWhere('key', 'backup')['status']);

        $this->actingAs($staff)->get('/admin')->assertOk()->assertSee('Saúde do sistema')->assertSee('Cópia de segurança');
    }
}
