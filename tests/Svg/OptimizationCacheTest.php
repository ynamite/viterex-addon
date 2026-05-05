<?php

namespace Ynamite\ViteRex\Tests\Svg;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Svg\OptimizationCache;

final class OptimizationCacheTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'viterex-cache-') . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    public function testEmptyWhenFileMissing(): void
    {
        $cache = new OptimizationCache('/nonexistent/path/cache.json');
        $this->assertFalse($cache->isFresh('any/path.svg', 'content'));
    }

    public function testRecordAndPersistRoundTrip(): void
    {
        $cache = new OptimizationCache($this->tmpFile);
        $cache->record('img/icon.svg', '<svg>final</svg>');
        $cache->persist();

        $reloaded = new OptimizationCache($this->tmpFile);
        $this->assertTrue($reloaded->isFresh('img/icon.svg', '<svg>final</svg>'));
        $this->assertFalse($reloaded->isFresh('img/icon.svg', '<svg>different</svg>'));
        $this->assertFalse($reloaded->isFresh('img/other.svg', '<svg>final</svg>'));
    }

    public function testClearEmptiesInMemoryEntries(): void
    {
        $cache = new OptimizationCache($this->tmpFile);
        $cache->record('img/icon.svg', '<svg/>');
        $cache->clear();
        $this->assertFalse($cache->isFresh('img/icon.svg', '<svg/>'));
    }

    public function testCorruptedJsonFailsOpen(): void
    {
        file_put_contents($this->tmpFile, '{not valid json');
        $cache = new OptimizationCache($this->tmpFile);
        $this->assertFalse($cache->isFresh('img/icon.svg', '<svg/>'));
        // Persist should overwrite the corrupted file with a valid (empty) object.
        $cache->persist();
        $this->assertSame([], json_decode((string) file_get_contents($this->tmpFile), true));
    }

    public function testNonStringEntriesAreSkippedOnLoad(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'img/good.svg' => sha1('<svg>x</svg>'),
            'img/bad.svg'  => ['not', 'a', 'string'],
        ]));
        $cache = new OptimizationCache($this->tmpFile);
        $this->assertTrue($cache->isFresh('img/good.svg', '<svg>x</svg>'));
        // Bad entry skipped on load — never present in the cache map.
        $this->assertFalse($cache->isFresh('img/bad.svg', 'anything'));
    }

    public function testPersistCreatesParentDirectory(): void
    {
        $nested = sys_get_temp_dir() . '/viterex-test-' . uniqid() . '/sub/dir/cache.json';
        $cache = new OptimizationCache($nested);
        $cache->record('a.svg', '<svg/>');
        $cache->persist();
        $this->assertFileExists($nested);
        @unlink($nested);
        @rmdir(dirname($nested));
        @rmdir(dirname($nested, 2));
        @rmdir(dirname($nested, 3));
    }
}
