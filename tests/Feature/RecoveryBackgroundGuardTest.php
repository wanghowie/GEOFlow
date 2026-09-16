<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoveryBackgroundGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/geoflow-recovery-background-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        config(['geoflow.recovery_contract_required' => true, 'geoflow.recovery_control_directory' => $this->directory]);
        file_put_contents($this->directory.'/state.json', json_encode([
            'schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32),
            'transaction_id' => 'recovery-test-01', 'phase' => 'http_ready', 'minimum_updater_protocol' => 5,
        ]));
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/state.json');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_daemon_pauses_before_requesting_a_job(): void
    {
        $this->assertFalse(Event::until(new Looping('database', 'default')));
    }

    public function test_once_worker_keeps_reserved_status_attempts_and_failed_callbacks_unchanged(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->expectException(ApiException::class);
        try {
            app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0, maxTries: 1));
        } finally {
            $this->assertSame(1, DB::table('jobs')->count());
            $this->assertSame(0, (int) DB::table('jobs')->value('attempts'));
            $this->assertNull(DB::table('jobs')->value('reserved_at'));
            $this->assertSame(0, DB::table('failed_jobs')->count());
        }
    }

    public function test_synchronous_job_is_blocked_before_handling_or_failed_callback(): void
    {
        RecoveryGuardProbe::$executed = false;
        RecoveryGuardProbe::$failed = false;
        $this->expectException(ApiException::class);
        try {
            Queue::connection('sync')->push(new RecoveryGuardProbe);
        } finally {
            $this->assertFalse(RecoveryGuardProbe::$executed);
            $this->assertFalse(RecoveryGuardProbe::$failed);
        }
    }
}

class RecoveryGuardProbe
{
    public static bool $executed = false;

    public static bool $failed = false;

    public function handle(): void
    {
        self::$executed = true;
    }

    public function failed(): void
    {
        self::$failed = true;
    }
}
