<?php

namespace Ynamite\ViteRex\Tests\Deploy;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Deploy\Page;

final class PageTest extends TestCase
{
    // --- state detection ---

    public function testDetectStateNeedsActivationWhenSidecarExistsButDeployFileLacksMarkers(): void
    {
        $state = Page::detectState(
            sidecar: ['repository' => 'r', 'hosts' => []],
            deployContents: "<?php\n\$x = 1;",
        );
        $this->assertSame(Page::STATE_NEEDS_ACTIVATION, $state);
    }

    public function testDetectStateActiveWhenSidecarExistsAndDeployFileHasMarkers(): void
    {
        $deploy = "<?php\n// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>\n"
            . "// <<< VITEREX:DEPLOY_CONFIG <<<\n";
        $state = Page::detectState(['repository' => 'r', 'hosts' => []], $deploy);
        $this->assertSame(Page::STATE_ACTIVE, $state);
    }

    public function testDetectStateNoSidecarWhenSidecarMissing(): void
    {
        $state = Page::detectState(null, "<?php");
        $this->assertSame(Page::STATE_NO_SIDECAR, $state);
    }

    public function testDetectStateMissingDeployFileWhenContentsAreNull(): void
    {
        $state = Page::detectState(null, null);
        $this->assertSame(Page::STATE_MISSING_DEPLOY_FILE, $state);
    }

    // --- validation ---

    public function testValidatePostAcceptsMinimalValidInput(): void
    {
        $post = [
            'repository' => 'git@github.com:u/r.git',
            'hosts' => [
                ['name' => 'stage', 'hostname' => 'h', 'port' => '22', 'user' => 'u', 'stage' => 'stage', 'path' => '/p'],
            ],
        ];
        $result = Page::validatePost($post);
        $this->assertSame([], $result['errors']);
        $this->assertSame(22, $result['cfg']['hosts'][0]['port']);
    }

    public function testValidatePostAllowsEmptyPort(): void
    {
        $post = ['repository' => 'r', 'hosts' => [
            ['name' => 's', 'hostname' => 'h', 'port' => '', 'user' => 'u', 'stage' => 's', 'path' => '/p'],
        ]];
        $result = Page::validatePost($post);
        $this->assertSame([], $result['errors']);
        $this->assertNull($result['cfg']['hosts'][0]['port']);
    }

    public function testValidatePostRejectsMissingRequiredHostFields(): void
    {
        $post = ['repository' => 'r', 'hosts' => [['name' => 's']]];
        $result = Page::validatePost($post);
        $this->assertNotEmpty($result['errors']);
        $this->assertNull($result['cfg']);
    }

    public function testValidatePostRejectsNonNumericPort(): void
    {
        $post = ['repository' => 'r', 'hosts' => [
            ['name' => 's', 'hostname' => 'h', 'port' => 'twenty-two', 'user' => 'u', 'stage' => 's', 'path' => '/p'],
        ]];
        $result = Page::validatePost($post);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidatePostRejectsDuplicateHostNames(): void
    {
        $post = ['repository' => 'r', 'hosts' => [
            ['name' => 's', 'hostname' => 'h', 'port' => '22', 'user' => 'u', 'stage' => 's', 'path' => '/p'],
            ['name' => 's', 'hostname' => 'h2', 'port' => '22', 'user' => 'u', 'stage' => 's', 'path' => '/p2'],
        ]];
        $result = Page::validatePost($post);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidatePostRejectsZeroHosts(): void
    {
        $post = ['repository' => 'r', 'hosts' => []];
        $result = Page::validatePost($post);
        $this->assertNotEmpty($result['errors']);
    }
}
