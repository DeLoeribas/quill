<?php

final class Storage
{
    /** @var callable[] Called with the path after every Storage::update() write, so derived caches can drop their copy. */
    private static array $writeListeners = [];

    public static function onWrite(callable $listener): void
    {
        self::$writeListeners[] = $listener;
    }

    /** Shared-lock read. Returns $default if the file doesn't exist or is invalid JSON. */
    public static function read(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return $default;
        }

        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : $default;
    }

    /**
     * Atomic read-modify-write. $mutator receives the current array (or
     * $default if the file is missing/invalid) and returns the new array
     * to persist. The exclusive lock is held for the full read-mutate-write
     * cycle so concurrent writers never overwrite each other's changes.
     */
    public static function update(string $path, array $default, callable $mutator): array
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($path, 'c+');
        if ($fh === false) {
            throw new RuntimeException("Unable to open {$path} for writing");
        }

        flock($fh, LOCK_EX);

        $raw = stream_get_contents($fh);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = $default;
        }

        $data = $mutator($data);

        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fh);
        // Still under the exclusive lock, so a reader rebuilding a derived cache (which
        // holds a shared lock on this file) can't slip in between the write and this.
        foreach (self::$writeListeners as $listener) {
            $listener($path);
        }
        flock($fh, LOCK_UN);
        fclose($fh);

        return $data;
    }
}
