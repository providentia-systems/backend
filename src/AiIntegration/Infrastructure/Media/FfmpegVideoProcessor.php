<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Media;

use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\Media\VideoProcessor;

final readonly class FfmpegVideoProcessor implements VideoProcessor
{
    public function __construct(
        private string $ffprobeBinary,
        private string $ffmpegBinary,
        private int $maxInputBytes,
        private int $maxDurationSeconds,
        private int $maxFrames,
        private int $maxFrameBytes,
        private int $maxProcessingSeconds,
    ) {
    }

    public function extractFrames(string $bytes): array
    {
        if (strlen($bytes) < 16 || strlen($bytes) > $this->maxInputBytes) {
            throw new AiProviderException('video_size_rejected', 'Video size is outside the configured limit.');
        }
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'providentia-video-' . bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700)) {
            throw new AiProviderException('video_processing_failed', 'An isolated video workspace could not be created.');
        }
        $input = $directory . DIRECTORY_SEPARATOR . 'input.media';
        $processingDeadline = microtime(true) + $this->maxProcessingSeconds;
        try {
            if (file_put_contents($input, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new AiProviderException('video_processing_failed', 'The staged video could not be prepared.');
            }
            chmod($input, 0600);
            $probe = $this->run([
                $this->ffprobeBinary,
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'json',
                $input,
            ], $directory, 1048576, $this->remainingSeconds($processingDeadline));
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($probe, true, 16, JSON_THROW_ON_ERROR);
            $format = is_array($decoded['format'] ?? null) ? $decoded['format'] : [];
            $duration = (float) ($format['duration'] ?? 0);
            if (! is_finite($duration) || $duration <= 0 || $duration > $this->maxDurationSeconds) {
                throw new AiProviderException('video_duration_rejected', 'Video duration is outside the configured limit.');
            }
            $frameCount = min($this->maxFrames, max(1, (int) ceil($duration)));
            $interval = $duration / ($frameCount + 1);
            $frames = [];
            for ($index = 1; $index <= $frameCount; $index++) {
                $offset = min($duration - 0.001, $interval * $index);
                $output = $directory . DIRECTORY_SEPARATOR . sprintf('frame-%03d.jpg', $index);
                $this->run([
                    $this->ffmpegBinary,
                    '-nostdin', '-hide_banner', '-loglevel', 'error',
                    '-ss', number_format($offset, 3, '.', ''),
                    '-i', $input,
                    '-frames:v', '1',
                    '-map_metadata', '-1',
                    '-vf', 'scale=min(1920\,iw):-2',
                    '-q:v', '3',
                    '-y',
                    $output,
                ], $directory, 1048576, $this->remainingSeconds($processingDeadline));
                $frame = is_file($output) ? file_get_contents($output) : false;
                if (! is_string($frame) || strlen($frame) < 16 || strlen($frame) > $this->maxFrameBytes) {
                    throw new AiProviderException('video_frame_rejected', 'A derived video frame exceeded safe limits.');
                }
                $frames[] = [
                    'offsetMs' => (int) round($offset * 1000),
                    'mimeType' => 'image/jpeg',
                    'bytes' => $frame,
                ];
            }

            return ['durationMs' => (int) round($duration * 1000), 'frames' => $frames];
        } catch (\JsonException) {
            throw new AiProviderException('video_probe_invalid', 'The video probe returned invalid metadata.');
        } finally {
            $this->removeWorkspace($directory);
        }
    }

    /** @param non-empty-list<string> $command */
    private function run(
        array $command,
        string $workingDirectory,
        int $maxOutputBytes,
        int $timeoutSeconds,
    ): string {
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin'],
            ['bypass_shell' => true],
        );
        if (! is_resource($process)) {
            throw new AiProviderException('video_processor_unavailable', 'The isolated video processor is unavailable.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $exitCode = -1;
        $failure = null;
        do {
            $status = proc_get_status($process);
            $read = [];
            if (! feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (! feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if ($read !== []) {
                $write = $except = [];
                @stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream, 8192);
                    if (is_string($chunk)) {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }
            if (strlen($stdout) > $maxOutputBytes || strlen($stderr) > $maxOutputBytes) {
                proc_terminate($process, 9);
                $failure = new AiProviderException(
                    'video_output_rejected',
                    'The video processor output exceeded safe limits.',
                );
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $failure = new AiProviderException(
                    'video_processing_timeout',
                    'The video processor exceeded its time limit.',
                );
                break;
            }
            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
        } while (true);
        $remaining = stream_get_contents($pipes[1]);
        $stdout .= is_string($remaining) ? $remaining : '';
        $remaining = stream_get_contents($pipes[2]);
        $stderr .= is_string($remaining) ? $remaining : '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closed;
        }
        if ($failure !== null) {
            throw $failure;
        }
        if (strlen($stdout) > $maxOutputBytes || strlen($stderr) > $maxOutputBytes || $exitCode !== 0) {
            throw new AiProviderException(
                'video_processing_failed',
                'The video processor rejected the input or failed safely.',
            );
        }

        return $stdout;
    }

    private function removeWorkspace(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        rmdir($directory);
    }

    private function remainingSeconds(float $deadline): int
    {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new AiProviderException('video_processing_timeout', 'The video processor exceeded its time limit.');
        }

        return $remaining;
    }
}
