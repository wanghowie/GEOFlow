<?php

namespace App\Services\SystemUpdater;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Host-only evidence collection. Decisions never dispatch or release restored work. */
final class RecoveryReconciliation
{
    public function __construct(private readonly RecoveryState $state) {}

    public function inspect(string $transaction, int $after = 0, int $limit = 100): array
    {
        $state = $this->boundary($transaction);
        $report = $this->verifySources($state);
        $entries = DB::table('recovery_quarantines')->where('epoch', $state['epoch'])
            ->where('id', '>', max(0, $after))->orderBy('id')->limit(max(1, min(200, $limit)))->get();

        return $this->identity($state) + [
            'status' => 'pass', 'proof_scope' => 'core_only', 'background_status' => 'held',
            'quarantine_count' => $report['quarantine_count'], 'quarantine_sha256' => $report['quarantine_sha256'],
            'entries' => $entries->map(fn (object $entry): array => [
                'quarantine_id' => $entry->id, 'source_table' => $entry->source_table, 'source_id' => $entry->source_id,
                'source_sha256' => $entry->source_sha256, 'summary' => json_decode($entry->summary, true, 32, JSON_THROW_ON_ERROR),
                'hold_reason' => 'replay_adapter_required',
            ])->all(),
            'next_after' => $entries->isEmpty() ? null : $entries->last()->id,
            'host_checks_required' => ['source_recovery_point', 'redis_quarantine', 'fresh_runtime'],
        ];
    }

    private function boundary(string $transaction): array
    {
        $state = $this->state->snapshot();
        if ($state === null || ! in_array($state['phase'], ['validating', 'http_ready'], true)
            || $state['transaction_id'] !== $transaction
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/', $transaction) !== 1) {
            throw new RuntimeException('recovery_reconciliation_boundary_invalid');
        }

        return $state;
    }

    private function identity(array $state): array
    {
        return ['schema_version' => 1, 'host_id' => $state['host_id'], 'instance_id' => $state['instance_id'],
            'epoch' => $state['epoch'], 'transaction_id' => $state['transaction_id']];
    }

    /** Recheck the complete source set, including additions after the preparation snapshot. */
    private function verifySources(array $state): array
    {
        $prepared = DB::table('recovery_preparations')->where('epoch', $state['epoch'])->first();
        if ($prepared === null || $prepared->transaction_id !== $state['transaction_id']) {
            throw new RuntimeException('recovery_reconciliation_preparation_missing');
        }
        $report = json_decode($prepared->report, true, 32, JSON_THROW_ON_ERROR);
        if (($report['epoch'] ?? null) !== $state['epoch'] || ($report['transaction_id'] ?? null) !== $state['transaction_id']
            || ($report['status'] ?? null) !== 'pass' || ! is_int($report['quarantine_count'] ?? null)
            || ! is_string($report['quarantine_sha256'] ?? null)) {
            throw new RuntimeException('recovery_reconciliation_preparation_invalid');
        }
        foreach (RecoveryPreparation::INTENTS as $table) {
            if (DB::table($table)->count() !== DB::table('recovery_quarantines')->where('epoch', $state['epoch'])->where('source_table', $table)->count()) {
                throw new RuntimeException('recovery_quarantine_changed');
            }
        }
        $hash = hash_init('sha256');
        $count = 0;
        foreach (DB::table('recovery_quarantines')->where('epoch', $state['epoch'])->orderBy('source_table')->orderBy('source_id')->cursor() as $entry) {
            if (! in_array($entry->source_table, RecoveryPreparation::INTENTS, true) || $entry->status !== 'held') {
                throw new RuntimeException('recovery_quarantine_changed');
            }
            $source = DB::table($entry->source_table)->where('id', $entry->source_id)->first();
            if ($source === null) {
                throw new RuntimeException('recovery_quarantine_changed');
            }
            $fields = (array) $source;
            ksort($fields);
            if (! hash_equals($entry->source_sha256, hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('recovery_quarantine_changed');
            }
            hash_update($hash, json_encode([$entry->source_table, $entry->source_id, $entry->source_sha256,
                json_decode($entry->summary, true, 32, JSON_THROW_ON_ERROR), $entry->status], JSON_THROW_ON_ERROR)."\n");
            $count++;
        }
        if ($count !== $report['quarantine_count'] || ! hash_equals($report['quarantine_sha256'], hash_final($hash))) {
            throw new RuntimeException('recovery_quarantine_changed');
        }

        return $report;
    }
}
