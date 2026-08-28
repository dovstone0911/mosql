<?php

namespace Dovstone\MoSQL\Cache;

class SqlFileCacheManager
{
    private string $cacheDir;
    private int $ttl;

    public function __construct(string $cacheDir = 'var/cache/sql', int $ttl = 3600)
    {
        $this->cacheDir = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . rtrim($cacheDir, '/');
        $this->ttl = $ttl;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
    }

    public function get(string $key): ?array
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $content = json_decode(file_get_contents($file), true);

        if (!$content || !isset($content['expires_at'], $content['data'])) {
            return null;
        }

        if (time() > $content['expires_at']) {
            unlink($file);
            return null;
        }

        return $content['data'];
    }

    public function set(string $key, array|callable $data, $executionTime = null): void
    {
        $file = $this->getFilePath($key);
        if (is_callable($data)) {
            $data = $data();
        }

        $executionTimeMs = $executionTime !== null ? round($executionTime * 1000, 2) : null;

        $payload = [
            'expires_at' => time() + $this->ttl,
            'data' => $data,
            'execution_time_ms' => $executionTimeMs,
            'execution_time' => $executionTimeMs !== null ? $executionTimeMs . ' ms' : 'N/A',
            'executed_at' => date('Y-m-d H:i:s')
        ];

        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT));
    }

    private function getFilePath(string $key): string
    {
        return $this->cacheDir . '/' . hash('sha256', $key) . '.cache';
    }
}
