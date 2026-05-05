<?php

namespace Ynamite\ViteRex\Tests;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Config;

/**
 * Pin Config::isCheckboxChecked across every value form rex_config can
 * legitimately hold for a checkbox field. The helper is the single source
 * of truth for parsing — any caller using `=== '1'` instead would be a
 * regression of the v3.0 `https_enabled` bug. Two paths land checkboxes
 * here: form-saved values (`|1|` checked, `''` unchecked) and
 * Config::DEFAULTS seeds (bare `'1'` / `'0'`).
 */
final class CheckboxValueTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function values(): iterable
    {
        yield 'form-saved checked'    => ['|1|', true];
        yield 'seeded default on'     => ['1',   true];
        yield 'form-saved unchecked'  => ['',    false];
        yield 'seeded default off'    => ['0',   false];
        yield 'legacy pipe-zero'      => ['|0|', false];
        yield 'pipes with no value'   => ['||',  false];
    }

    /**
     * @dataProvider values
     */
    public function testIsCheckboxChecked(string $stored, bool $expected): void
    {
        $this->assertSame($expected, Config::isCheckboxChecked($stored));
    }
}
