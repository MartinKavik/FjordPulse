<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

final class SingleProcessLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Realtime process lock path cannot be empty.');
        }
    }

    public function acquire(): void
    {
        if (is_resource($this->handle)) {
            return;
        }
        $handle = fopen($this->path, 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Unable to open realtime process lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another FjordPulse realtime process is already running.');
        }
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);
        $this->handle = $handle;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
