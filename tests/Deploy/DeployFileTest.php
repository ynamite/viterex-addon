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

    // --- rewrite: first-time activation ---

    public function testRewriteReplacesHostChainAndPreservesEverythingElse(): void
    {
        $orig = $this->fixture('single-host.php');
        $extracted = DeployFile::extract($orig);
        $this->assertNotNull($extracted);

        $rewritten = DeployFile::rewrite($orig, $extracted);

        // marker region present
        $this->assertStringContainsString(DeployFile::MARKER_OPEN, $rewritten);
        $this->assertStringContainsString(DeployFile::MARKER_CLOSE, $rewritten);
        // sidecar require + foreach block present
        $this->assertStringContainsString("\$cfg = require __DIR__ . '/deploy.config.php';", $rewritten);
        $this->assertStringContainsString('foreach ($cfg[\'hosts\'] as $h)', $rewritten);
        // user code OUTSIDE the host chain survives:
        // - prologue $deployment* vars (orphaned but preserved)
        $this->assertStringContainsString('$deploymentName =', $rewritten);
        $this->assertStringContainsString('$deploymentHost =', $rewritten);
        // - require above the host chain (real-world layout)
        $this->assertStringContainsString("require __DIR__ . '/src/addons/ydeploy/deploy.php';", $rewritten);
        // - user's set('repository', $deploymentRepository) above (overridden by marker block at runtime)
        $this->assertStringContainsString("set('repository', \$deploymentRepository);", $rewritten);
        // - custom task below
        $this->assertStringContainsString("task('custom:hello'", $rewritten);
        // host chain itself removed
        $this->assertStringNotContainsString('->setHostname($deploymentHost)', $rewritten);
    }

    public function testRewriteReplacesAllHostChainsForMultiHost(): void
    {
        $orig = $this->fixture('multi-host.php');
        $extracted = DeployFile::extract($orig);
        $this->assertNotNull($extracted);

        $rewritten = DeployFile::rewrite($orig, $extracted);

        $this->assertStringContainsString(DeployFile::MARKER_OPEN, $rewritten);
        // both original host chains removed
        $this->assertStringNotContainsString("host(\$deploymentName)", $rewritten);
        $this->assertStringNotContainsString("host('prod')", $rewritten);
        // exactly one foreach block injected
        $this->assertSame(1, substr_count($rewritten, 'foreach ($cfg[\'hosts\']'));
        // prologue + user set survive
        $this->assertStringContainsString('$deploymentName =', $rewritten);
        $this->assertStringContainsString("set('repository', \$deploymentRepository);", $rewritten);
    }

    public function testRewriteReturnsUnchangedWhenNoMarkersAndNoExtractable(): void
    {
        // file with neither markers nor a recognizable prologue+host shape
        // → rewrite has nothing to do; returns input unchanged
        $contents = $this->fixture('bare-php.php');
        $this->assertSame($contents, DeployFile::rewrite($contents, null));
    }

    // --- rewrite: re-activation / idempotency ---

    public function testRewriteReplacesMarkerRegionWhenAlreadyActivated(): void
    {
        $original = $this->fixture('with-markers.php');
        // Mutate the marker region to simulate a stale viterex injection
        // (e.g., a future viterex version added a new line). The rewrite
        // should normalize it back to the canonical marker region.
        $tampered = str_replace(
            "\$cfg = require __DIR__ . '/deploy.config.php';",
            "\$cfg = require __DIR__ . '/deploy.config.php'; // STALE",
            $original,
        );

        $rewritten = DeployFile::rewrite($tampered, null);

        $this->assertStringNotContainsString('// STALE', $rewritten);
        $this->assertStringContainsString("\$cfg = require __DIR__ . '/deploy.config.php';\n", $rewritten);
        // user code below the markers preserved
        $this->assertStringContainsString("task('custom:hello'", $rewritten);
    }

    public function testRewriteIsIdempotent(): void
    {
        $orig = $this->fixture('single-host.php');
        $extracted = DeployFile::extract($orig);
        $first = DeployFile::rewrite($orig, $extracted);
        $second = DeployFile::rewrite($first, null); // markers exist now → no extracted needed

        $this->assertSame($first, $second);
    }

    // --- rewrite: tampered markers ---

    public function testRewriteIsNoOpWhenMarkersTamperedAndNoExtractable(): void
    {
        $contents = $this->fixture('tampered-opening-only.php');
        // hasMarkers() is false (only opening). No prologue assignments either.
        // → rewrite has no markers to replace and no shape to extract → unchanged.
        $this->assertFalse(DeployFile::hasMarkers($contents));
        $this->assertSame($contents, DeployFile::rewrite($contents, DeployFile::extract($contents)));
    }
}
