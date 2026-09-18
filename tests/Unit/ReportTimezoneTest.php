<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reports print and default their dates in Philippine time. APP_TIMEZONE is not
 * set in every environment, so an unqualified call to the clock falls back to UTC
 * and the report reads eight hours behind — the bug this guards against.
 *
 * Every such call in report code must name its timezone: now('Asia/Manila'),
 * Carbon::today('Asia/Manila'), and so on.
 */
class ReportTimezoneTest extends TestCase
{
    private const REPORT_PATHS = [
        'app/Exports',
        'app/Http/Controllers',
        'resources/views/pdf',
    ];

    /** Only files whose name marks them as report or export code. */
    private const REPORT_FILE_PATTERN = '/(Report|Export)/';

    public function test_report_code_never_reads_the_clock_without_a_timezone(): void
    {
        $offenders = [];

        foreach ($this->reportFiles() as $file) {
            foreach (file($file) as $index => $line) {
                // Prose in a comment may name the function; only real calls count.
                if (preg_match('#^\s*(//|\*|/\*|\#)#', $line)) {
                    continue;
                }

                if (preg_match('/(?<![:\w>$])(Carbon::)?(now|today)\(\s*\)/', $line)) {
                    $offenders[] = $this->relative($file).':'.($index + 1).' — '.trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Report code must pass a timezone, e.g. now('Asia/Manila'):\n".implode("\n", $offenders)
        );
    }

    private function reportFiles(): array
    {
        $files = [];

        foreach (self::REPORT_PATHS as $path) {
            $directory = $this->projectRoot().'/'.$path;

            if (! is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if ($file->isFile() && preg_match(self::REPORT_FILE_PATTERN, $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        return str_replace($this->projectRoot().'/', '', $file);
    }

    private function projectRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }
}
