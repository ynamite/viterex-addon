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
}
