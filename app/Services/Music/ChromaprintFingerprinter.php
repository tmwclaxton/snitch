<?php

namespace App\Services\Music;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Wrap the `fpcalc` binary from libchromaprint. Returns a fingerprint + duration
 * pair suitable for AcoustID's /lookup endpoint. Missing binary returns null so
 * the caller can fall back to AudD without a hard failure.
 */
class ChromaprintFingerprinter
{
    /**
     * @return array{fingerprint: string, duration: int}|null
     */
    public function fingerprint(string $audioPath): ?array
    {
        if (! is_file($audioPath)) {
            return null;
        }

        $binary = (string) config('snitch.music_recognition.fpcalc_binary', 'fpcalc');

        $result = Process::timeout(45)->run([
            $binary,
            '-json',
            $audioPath,
        ]);

        if (! $result->successful()) {
            $stderr = strtolower($result->errorOutput().$result->output());

            if ($this->looksLikeMissingBinary($result->exitCode(), $stderr)) {
                Log::info('ChromaprintFingerprinter fpcalc binary is unavailable; AcoustID step will be skipped.', [
                    'exit_code' => $result->exitCode(),
                ]);

                return null;
            }

            Log::info('ChromaprintFingerprinter fpcalc call failed.', [
                'exit_code' => $result->exitCode(),
                'stderr' => mb_substr((string) $result->errorOutput(), 0, 400),
            ]);

            return null;
        }

        $decoded = json_decode((string) $result->output(), true);

        if (! is_array($decoded)) {
            return null;
        }

        $fingerprint = isset($decoded['fingerprint']) && is_string($decoded['fingerprint'])
            ? $decoded['fingerprint']
            : null;
        $duration = isset($decoded['duration']) && is_numeric($decoded['duration'])
            ? (int) round((float) $decoded['duration'])
            : null;

        if ($fingerprint === null || $fingerprint === '' || $duration === null || $duration <= 0) {
            return null;
        }

        return [
            'fingerprint' => $fingerprint,
            'duration' => $duration,
        ];
    }

    private function looksLikeMissingBinary(?int $exitCode, string $stderr): bool
    {
        if (in_array($exitCode, [126, 127], true)) {
            return true;
        }

        return str_contains($stderr, 'command not found')
            || str_contains($stderr, 'no such file');
    }
}
