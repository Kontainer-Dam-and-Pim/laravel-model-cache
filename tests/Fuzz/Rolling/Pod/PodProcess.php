<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Release;
use RuntimeException;

/**
 * The coordinator's handle on a pod process: it starts the process, sends it steps and waits for
 * their outcome, and stops it.
 */
final class PodProcess
{
    private const int REPLY_TIMEOUT_SECONDS = 60;

    /**
     * @param  resource  $process
     * @param  array{resource, resource}  $pipes  the pod's input and output
     */
    private function __construct(
        public readonly string $name,
        public readonly int $release,
        public readonly bool $secondConnection,
        private $process,
        private array $pipes,
        private readonly string $errorLog,
    ) {}

    /**
     * @param  array<string, mixed>  $config  configuration the pod's application is booted with
     */
    public static function start(string $name, Release $release, bool $secondConnection, array $config): self
    {
        $errorLog = (string) tempnam(sys_get_temp_dir(), "model-cache-pod-{$name}-");
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/pod.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errorLog, 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException("Could not start pod {$name}");
        }

        $pod = new self($name, $release->number, $secondConnection, $process, [$pipes[0], $pipes[1]], $errorLog);
        $pod->send(['config' => $config, 'release' => $release->toArray()]);

        return $pod;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function send(array $message): array
    {
        $this->dispatch($message);

        return $this->receive();
    }

    /**
     * Sends a message without waiting for the reply, which `receive()` reads, so several pods
     * can work at once.
     *
     * @param  array<string, mixed>  $message
     */
    public function dispatch(array $message): void
    {
        fwrite($this->pipes[0], json_encode($message, JSON_THROW_ON_ERROR)."\n");
        fflush($this->pipes[0]);
    }

    /**
     * @return array<string, mixed>
     */
    public function receive(): array
    {
        $read = [$this->pipes[1]];
        $none = [];
        if (stream_select($read, $none, $none, self::REPLY_TIMEOUT_SECONDS) !== 1 || ($line = fgets($this->pipes[1])) === false) {
            throw new RuntimeException("Pod {$this->name} gave no reply\n".$this->errors());
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException("Pod {$this->name} replied with something other than JSON: {$line}\n".$this->errors(), previous: $exception);
        }
    }

    /**
     * Ends the pod's input, so it finishes its request and exits.
     */
    public function stop(): void
    {
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        $status = proc_close($this->process);
        $errors = $this->errors();
        unlink($this->errorLog);

        if ($status !== 0) {
            throw new RuntimeException("Pod {$this->name} exited with status {$status}\n{$errors}");
        }
    }

    /**
     * Kills the pod, as a node failing takes a pod down mid-request.
     */
    public function kill(): void
    {
        proc_terminate($this->process, SIGKILL);
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        proc_close($this->process);
        unlink($this->errorLog);
    }

    private function errors(): string
    {
        return (string) file_get_contents($this->errorLog);
    }
}
