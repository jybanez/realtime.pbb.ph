<?php

namespace App\Realtime\Media;

use App\Models\RealtimeMediaChunk;
use App\Realtime\Auth\RealtimeTokenClaims;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class RealtimeMediaChunkQueue
{
    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(RealtimeTokenClaims $claims, string $room, string $sessionId, array $payload): RealtimeMediaChunkSpoolEntry
    {
        return $this->enqueueWithBinary($claims, $room, $sessionId, $payload, null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueueBinary(RealtimeTokenClaims $claims, string $room, string $sessionId, array $payload, string $binaryData): RealtimeMediaChunkSpoolEntry
    {
        return $this->enqueueWithBinary($claims, $room, $sessionId, $payload, $binaryData);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function enqueueWithBinary(RealtimeTokenClaims $claims, string $room, string $sessionId, array $payload, ?string $binaryData): RealtimeMediaChunkSpoolEntry
    {
        $chunkId = 'mchunk_' . Str::lower((string) Str::ulid());
        $spoolPath = $this->pendingPath((string) $claims->appCode, $chunkId);
        $binaryPath = $binaryData !== null ? $this->binaryPathForJsonPath($spoolPath) : null;
        $entry = new RealtimeMediaChunkSpoolEntry(
            chunk_id: $chunkId,
            client_code: (string) $claims->appCode,
            project_code: (string) $claims->projectCode,
            room: trim($room),
            session_id: trim($sessionId) !== '' ? trim($sessionId) : null,
            user_id: $claims->userId,
            display_name: $claims->displayName,
            status: 'pending',
            attempts: 0,
            payload: $payload,
            meta: [
                'source' => 'client',
                'sender' => [
                    'user_id' => $claims->userId,
                    'display_name' => $claims->displayName,
                    'project_code' => $claims->projectCode,
                    'app_code' => $claims->appCode,
                ],
            ],
            queued_at: now(),
            forwarded_at: null,
            failed_at: null,
            failure_reason: null,
            downstream_status: null,
            spool_path: $spoolPath,
            binary_path: $binaryPath,
        );

        if ($binaryData !== null && $binaryPath !== null) {
            $this->writeBinaryAtomically($binaryPath, $binaryData);
        }
        $this->writeAtomically($entry->spool_path, $entry->toArray());

        return $entry;
    }

    /**
     * @return Collection<int, RealtimeMediaChunkSpoolEntry>
     */
    public function claimBatch(int $limit = 25, int $claimTimeoutSeconds = 300): Collection
    {
        $limit = max(1, $limit);
        $staleCutoff = Carbon::now()->subSeconds(max(30, $claimTimeoutSeconds))->getTimestamp();
        $claimed = collect();

        foreach ($this->candidateFiles($limit * 2, $staleCutoff) as $candidate) {
            if ($claimed->count() >= $limit) {
                break;
            }

            $entry = $this->claimCandidate($candidate['path'], $candidate['client_code'], $candidate['state'], $staleCutoff);
            if ($entry instanceof RealtimeMediaChunkSpoolEntry) {
                $claimed->push($entry);
            }
        }

        return $claimed;
    }

    public function markForwarded(RealtimeMediaChunkSpoolEntry $entry, ?int $downstreamStatus = null): void
    {
        $entry->status = 'forwarded';
        $entry->downstream_status = $downstreamStatus;
        $entry->forwarded_at = now();
        $entry->failed_at = null;
        $entry->failure_reason = null;
        $this->deleteSpoolFile($entry->binary_path);
        $this->deleteSpoolFile($entry->spool_path);
    }

    public function markFailed(
        RealtimeMediaChunkSpoolEntry $entry,
        string $reason,
        ?int $downstreamStatus = null
    ): RealtimeMediaChunk {
        $entry->status = 'failed';
        $entry->forwarded_at = null;
        $entry->failed_at = now();
        $entry->failure_reason = $reason;
        $entry->downstream_status = $downstreamStatus;

        $record = RealtimeMediaChunk::query()->updateOrCreate(
            ['chunk_id' => $entry->chunk_id],
            [
                'client_code' => $entry->client_code,
                'project_code' => $entry->project_code,
                'room' => $entry->room,
                'session_id' => $entry->session_id,
                'user_id' => $entry->user_id,
                'display_name' => $entry->display_name,
                'status' => $entry->status,
                'attempts' => $entry->attempts,
                'payload' => $entry->payload,
                'meta' => $entry->meta,
                'queued_at' => $entry->queued_at,
                'forwarded_at' => $entry->forwarded_at,
                'failed_at' => $entry->failed_at,
                'failure_reason' => $entry->failure_reason,
                'downstream_status' => $entry->downstream_status,
            ]
        );

        $this->deleteSpoolFile($entry->binary_path);
        $this->deleteSpoolFile($entry->spool_path);

        return $record;
    }

    public function spoolBasePath(): string
    {
        return rtrim((string) config('realtime.media_chunk_spool_path', storage_path('app/realtime-media-chunks')), DIRECTORY_SEPARATOR);
    }

    private function pendingPath(string $clientCode, string $chunkId): string
    {
        return $this->statePath($clientCode, 'pending') . DIRECTORY_SEPARATOR . $chunkId . '.json';
    }

    private function processingPath(string $clientCode, string $chunkId): string
    {
        return $this->statePath($clientCode, 'processing') . DIRECTORY_SEPARATOR . $chunkId . '.json';
    }

    private function statePath(string $clientCode, string $state): string
    {
        return $this->spoolBasePath() . DIRECTORY_SEPARATOR . trim($clientCode) . DIRECTORY_SEPARATOR . $state;
    }

    /**
     * @return array<int, array{path:string,client_code:string,state:string,mtime:int}>
     */
    private function candidateFiles(int $limit, int $staleCutoff): array
    {
        $base = $this->spoolBasePath();
        if (!is_dir($base)) {
            return [];
        }

        $candidates = [];
        foreach (File::directories($base) as $clientDir) {
            $clientCode = basename($clientDir);

            $pendingDir = $this->statePath($clientCode, 'pending');
            if (is_dir($pendingDir)) {
                foreach (File::files($pendingDir) as $file) {
                    if ($file->getExtension() !== 'json') {
                        continue;
                    }

                    $candidates[] = [
                        'path' => $file->getPathname(),
                        'client_code' => $clientCode,
                        'state' => 'pending',
                        'mtime' => $file->getMTime(),
                    ];
                }
            }

            $processingDir = $this->statePath($clientCode, 'processing');
            if (is_dir($processingDir)) {
                foreach (File::files($processingDir) as $file) {
                    if ($file->getExtension() !== 'json' || $file->getMTime() > $staleCutoff) {
                        continue;
                    }

                    $candidates[] = [
                        'path' => $file->getPathname(),
                        'client_code' => $clientCode,
                        'state' => 'processing',
                        'mtime' => $file->getMTime(),
                    ];
                }
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$a['mtime'], $a['path']] <=> [$b['mtime'], $b['path']]);

        return array_slice($candidates, 0, max(1, $limit));
    }

    private function claimCandidate(string $path, string $clientCode, string $state, int $staleCutoff): ?RealtimeMediaChunkSpoolEntry
    {
        if ($state === 'processing') {
            clearstatcache(true, $path);
            if (!is_file($path) || filemtime($path) === false || filemtime($path) > $staleCutoff) {
                return null;
            }

            $pendingPath = $this->pendingPath($clientCode, pathinfo($path, PATHINFO_FILENAME));
            @File::ensureDirectoryExists(dirname($pendingPath));
            if (!@rename($path, $pendingPath)) {
                return null;
            }
            $processingBinaryPath = $this->binaryPathForJsonPath($path);
            $pendingBinaryPath = $this->binaryPathForJsonPath($pendingPath);
            if (is_file($processingBinaryPath)) {
                @rename($processingBinaryPath, $pendingBinaryPath);
            }
            $path = $pendingPath;
        }

        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }

        $chunkId = pathinfo($path, PATHINFO_FILENAME);
        $processingPath = $this->processingPath($clientCode, $chunkId);
        @File::ensureDirectoryExists(dirname($processingPath));
        if (!@rename($path, $processingPath)) {
            return null;
        }

        $entry = $this->readEntry($processingPath);
        $pendingBinaryPath = $this->binaryPathForJsonPath($path);
        $processingBinaryPath = $this->binaryPathForJsonPath($processingPath);
        if ($entry->binary_path !== null && is_file($pendingBinaryPath)) {
            @rename($pendingBinaryPath, $processingBinaryPath);
            $entry->binary_path = $processingBinaryPath;
        }
        $entry->status = 'dispatching';
        $entry->attempts += 1;
        $entry->spool_path = $processingPath;
        $this->writeAtomically($processingPath, $entry->toArray());

        return $entry;
    }

    private function readEntry(string $path): RealtimeMediaChunkSpoolEntry
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read media chunk spool file [%s].', $path));
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Invalid media chunk spool file contents [%s].', $path));
        }

        return RealtimeMediaChunkSpoolEntry::fromArray($data, $path);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeAtomically(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path));
        $tempPath = $path . '.tmp';
        file_put_contents($tempPath, json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
        if (is_file($path)) {
            @unlink($path);
        }
        @rename($tempPath, $path);
    }

    private function writeBinaryAtomically(string $path, string $data): void
    {
        File::ensureDirectoryExists(dirname($path));
        $tempPath = $path . '.tmp';
        file_put_contents($tempPath, $data, LOCK_EX);
        if (is_file($path)) {
            @unlink($path);
        }
        @rename($tempPath, $path);
    }

    private function binaryPathForJsonPath(string $path): string
    {
        return preg_replace('/\.json$/', '.bin', $path) ?: ($path . '.bin');
    }

    private function deleteSpoolFile(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}
