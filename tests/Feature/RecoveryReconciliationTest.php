<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\SystemUpdater\RecoveryPreparation;
use App\Services\SystemUpdater\RecoveryReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecoveryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/geoflow-reconcile-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        config(['geoflow.recovery_contract_required' => true, 'geoflow.recovery_control_directory' => $this->directory]);
        $this->state('validating');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function state(string $phase): void
    {
        file_put_contents($this->directory.'/state.json', json_encode([
            'schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32),
            'transaction_id' => 'recovery-test-01', 'phase' => $phase, 'minimum_updater_protocol' => 5,
        ]));
    }

    private function prepare(): void
    {
        Admin::query()->create(['username' => 'restore-admin', 'password' => 'fixture-password', 'role' => 'super_admin', 'status' => 'active']);
        $preparation = app(RecoveryPreparation::class);
        $preparation->prepare('recovery-test-01', $preparation->inspect()['admin_digest']);
        $this->state('http_ready');
    }

    public function test_inspection_preserves_unknown_work_and_returns_only_redacted_identity(): void
    {
        DB::table('jobs')->insert(['queue' => 'unknown-custom-queue', 'payload' => '{"secret":"never-print-me"}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->prepare();

        $report = app(RecoveryReconciliation::class)->inspect('recovery-test-01');

        $this->assertSame('held', $report['background_status']);
        $this->assertSame('core_only', $report['proof_scope']);
        $this->assertSame(1, $report['quarantine_count']);
        $this->assertSame('jobs', $report['entries'][0]['source_table']);
        $this->assertSame('replay_adapter_required', $report['entries'][0]['hold_reason']);
        $this->assertStringNotContainsString('never-print-me', json_encode($report));
        $this->assertSame('{"secret":"never-print-me"}', DB::table('jobs')->value('payload'));
    }

    public function test_fixed_inspect_command_is_available_while_business_commands_remain_held(): void
    {
        $this->prepare();
        $exit = Artisan::call('geoflow:recovery', ['--phase' => 'reconcile-inspect', '--transaction' => 'recovery-test-01', '--json' => true]);

        $this->assertSame(0, $exit);
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('held', $report['background_status']);
        $this->assertSame(0, $report['quarantine_count']);
        $this->assertSame([], $report['entries']);
    }

    public function test_inspect_rejects_new_source_identity_even_when_existing_rows_are_unchanged(): void
    {
        $this->prepare();
        DB::table('jobs')->insert(['queue' => 'unexpected', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->expectExceptionMessage('recovery_quarantine_changed');
        app(RecoveryReconciliation::class)->inspect('recovery-test-01');
    }

    public function test_inspect_rejects_changed_source_bytes_even_when_source_identity_is_the_same(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->prepare();
        DB::table('jobs')->update(['payload' => '{"changed":true}']);
        $this->expectExceptionMessage('recovery_quarantine_changed');
        app(RecoveryReconciliation::class)->inspect('recovery-test-01');
    }

    public function test_inspect_does_not_accept_another_transaction_or_unprepared_epoch(): void
    {
        $this->prepare();
        $exit = Artisan::call('geoflow:recovery', ['--phase' => 'reconcile-inspect', '--transaction' => 'different-transaction', '--json' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('recovery_reconciliation_boundary_invalid', json_decode(Artisan::output(), true)['error']);
    }
}
