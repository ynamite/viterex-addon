<?php

namespace Ynamite\ViteRex\Tests\Deploy;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Deploy\DeployFile;

final class DeployFileTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../fixtures/deploy/' . $name;
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail("Missing fixture: {$path}");
        }
        return $contents;
    }

    // --- hasMarkers ---

    public function testHasMarkersTrueWhenBothMarkersPresent(): void
    {
        $this->assertTrue(DeployFile::hasMarkers($this->fixture('with-markers.php')));
    }

    public function testHasMarkersFalseWhenNoMarkers(): void
    {
        $this->assertFalse(DeployFile::hasMarkers($this->fixture('single-host.php')));
    }

    public function testHasMarkersFalseWhenOnlyOpening(): void
    {
        $this->assertFalse(DeployFile::hasMarkers($this->fixture('tampered-opening-only.php')));
    }

    public function testHasMarkersFalseWhenOnlyClosing(): void
    {
        $contents = "<?php\n// <<< VITEREX:DEPLOY_CONFIG <<<\n";
        $this->assertFalse(DeployFile::hasMarkers($contents));
    }

    public function testHasMarkersFalseWhenDuplicateOpenings(): void
    {
        $contents = "<?php\n"
            . "// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>\n"
            . "// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>\n"
            . "// <<< VITEREX:DEPLOY_CONFIG <<<\n";
        $this->assertFalse(DeployFile::hasMarkers($contents));
    }

    public function testHasMarkersFalseWhenMarkerInsideStringLiteral(): void
    {
        // markers appearing inside a quoted string must not count
        $contents = "<?php\n\$s = '// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>';\n"
            . "\$t = '// <<< VITEREX:DEPLOY_CONFIG <<<';\n";
        $this->assertFalse(DeployFile::hasMarkers($contents));
    }

    // --- extract: happy paths ---

    public function testExtractSingleHost(): void
    {
        $cfg = DeployFile::extract($this->fixture('single-host.php'));

        $this->assertNotNull($cfg);
        $this->assertSame('git@github.com:user/repo.git', $cfg['repository']);
        $this->assertCount(1, $cfg['hosts']);
        $this->assertSame([
            'name' => 'staging',
            'hostname' => 'example.com',
            'port' => 22,
            'user' => 'webuser',
            'stage' => 'stage',
            'path' => '/var/www/staging',
        ], $cfg['hosts'][0]);
    }

    public function testExtractMultiHost(): void
    {
        $cfg = DeployFile::extract($this->fixture('multi-host.php'));

        $this->assertNotNull($cfg);
        $this->assertSame('git@github.com:user/repo.git', $cfg['repository']);
        $this->assertCount(2, $cfg['hosts']);

        $this->assertSame('stage', $cfg['hosts'][0]['name']);
        $this->assertSame('shared.example.com', $cfg['hosts'][0]['hostname']);
        $this->assertSame(22, $cfg['hosts'][0]['port']);

        $this->assertSame('prod', $cfg['hosts'][1]['name']);
        $this->assertSame('prod', $cfg['hosts'][1]['stage']);
        $this->assertSame('/var/www/prod', $cfg['hosts'][1]['path']);
    }

    // --- extract: null cases ---

    public function testExtractReturnsNullForPrologueWithoutHost(): void
    {
        $this->assertNull(DeployFile::extract($this->fixture('prologue-no-host.php')));
    }

    public function testExtractReturnsNullForBareFile(): void
    {
        $this->assertNull(DeployFile::extract($this->fixture('bare-php.php')));
    }

    public function testExtractReturnsNullForUnrecognizedHostChain(): void
    {
        // file uses ->set('hostname', ...) instead of ->setHostname(...)
        // → hostname not collected → host doesn't meet minimum → not added
        $this->assertNull(DeployFile::extract($this->fixture('unrecognized-host-chain.php')));
    }

    public function testExtractReturnsNullForSyntacticallyInvalidFile(): void
    {
        // PHP < 8 returned partial tokens for invalid PHP; on 8+ token_get_all
        // raises a parse warning but still returns tokens for the valid prefix.
        // Either way, this fixture has no recognizable prologue/hosts → null.
        $contents = "<?php this is not valid php at all !@#";
        $this->assertNull(@DeployFile::extract($contents));
    }

    // --- extract: source-encoding edge cases ---

    public function testExtractHandlesCrlfLineEndings(): void
    {
        $contents = str_replace("\n", "\r\n", $this->fixture('single-host.php'));
        $cfg = DeployFile::extract($contents);
        $this->assertNotNull($cfg);
        $this->assertSame('staging', $cfg['hosts'][0]['name']);
    }

    public function testExtractHandlesLeadingBom(): void
    {
        $contents = "\xEF\xBB\xBF" . $this->fixture('single-host.php');
        $cfg = DeployFile::extract($contents);
        $this->assertNotNull($cfg);
        $this->assertSame('staging', $cfg['hosts'][0]['name']);
    }
}
