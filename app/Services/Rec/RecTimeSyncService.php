<?php

namespace App\Services\Rec;

class RecTimeSyncService
{
    /**
     * @return array{server_received_at_ms: int, server_sent_at_ms: int, rtt_ms: int|null, offset_ms: float|null}
     */
    public function sample(?int $clientSentAtMs, ?int $serverReceivedAtMs = null): array
    {
        $serverReceivedAtMs ??= (int) floor(microtime(true) * 1000);
        $serverSentAtMs = (int) floor(microtime(true) * 1000);

        $rttMs = null;
        $offsetMs = null;

        if ($clientSentAtMs !== null) {
            $rttMs = max(0, $serverSentAtMs - $clientSentAtMs);
            $offsetMs = (($serverReceivedAtMs + $serverSentAtMs) / 2) - $clientSentAtMs;
        }

        return [
            'server_received_at_ms' => $serverReceivedAtMs,
            'server_sent_at_ms' => $serverSentAtMs,
            'rtt_ms' => $rttMs,
            'offset_ms' => $offsetMs,
        ];
    }

    /**
     * @param  list<array{rtt_ms: int|null, offset_ms: float|null}>  $samples
     */
    public function bestOffset(array $samples, int $maxRttMs = 500): ?float
    {
        $valid = array_values(array_filter(
            $samples,
            fn (array $sample) => $sample['offset_ms'] !== null
                && $sample['rtt_ms'] !== null
                && $sample['rtt_ms'] <= $maxRttMs,
        ));

        if ($valid === []) {
            return null;
        }

        usort($valid, fn (array $a, array $b) => ($a['rtt_ms'] ?? PHP_INT_MAX) <=> ($b['rtt_ms'] ?? PHP_INT_MAX));

        return (float) $valid[0]['offset_ms'];
    }
}
