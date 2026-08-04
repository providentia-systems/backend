<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Infrastructure\Media\FfmpegVideoProcessor;

final class FfmpegVideoProcessorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/providentia-ffmpeg-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testArgvProcessorsProduceBoundedFramesWithoutShellInterpolation(): void
    {
        $probe = $this->script('probe', <<<'SH'
#!/bin/sh
printf '{"format":{"duration":"2.0"}}'
SH);
        $ffmpeg = $this->script('ffmpeg', <<<'SH'
#!/bin/sh
for last do :; done
printf '\377\330\37701234567890123456789' > "$last"
SH);
        $processor = new FfmpegVideoProcessor($probe, $ffmpeg, 1024, 5, 2, 1024, 30);
        $result = $processor->extractFrames(str_repeat('v', 32));

        self::assertSame(2000, $result['durationMs']);
        self::assertCount(2, $result['frames']);
        self::assertSame('image/jpeg', $result['frames'][0]['mimeType']);
        self::assertGreaterThan(0, $result['frames'][0]['offsetMs']);
    }

    public function testProbeFailureIsReportedWithoutExecutingFallbackShell(): void
    {
        $false = $this->script('false', "#!/bin/sh\nexit 7\n");
        $processor = new FfmpegVideoProcessor($false, $false, 1024, 5, 2, 1024, 30);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('failed safely');
        $processor->extractFrames(str_repeat('v', 32));
    }

    private function script(string $name, string $body): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $body);
        chmod($path, 0700);

        return $path;
    }
}
