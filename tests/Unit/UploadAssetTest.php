<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UploadAssetTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function scenarios(): array
    {
        return ['response' => ['response'], 'queue' => ['queue'], 'format' => ['format'], 'export' => ['export'], 'dom' => ['dom']];
    }

    #[DataProvider('scenarios')]
    public function test_upload_boundaries(string $scenario): void
    {
        $process = proc_open(['node', __DIR__.'/upload-assets.cjs', $scenario], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output.$error);
        $this->assertStringContainsString('PASS '.$scenario, $output);
    }
}
