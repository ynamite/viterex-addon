<?php

namespace Ynamite\ViteRex;

use rex;
use rex_extension_point;

final class OutputFilter
{
    public static function register(rex_extension_point $ep): void
    {
        if (rex::isBackend()) {
            return;
        }

        $content = $ep->getSubject();
        if (!is_string($content) || $content === '') {
            return;
        }

        $matchCount = 0;
        $replaced = preg_replace_callback(
            '/(?<!\w)REX_VITE(?:\[([^\]]*)\])?/',
            static function (array $matches): string {
                $attrs = $matches[1] ?? '';
                return Assets::renderBlock(self::parseEntries($attrs));
            },
            $content,
            -1,
            $matchCount,
        );

        if ($replaced === null) {
            return;
        }

        if ($matchCount === 0) {
            $block = Assets::renderBlock(null);
            if ($block !== '') {
                $autoInsert = preg_replace(
                    '/<\/head>/i',
                    $block . "\n</head>",
                    $replaced,
                    1,
                    $autoCount,
                );
                if ($autoInsert !== null && $autoCount === 1) {
                    $replaced = $autoInsert;
                }
            }
        }

        $ep->setSubject($replaced);
    }

    private static function parseEntries(string $attrs): ?array
    {
        $attrs = trim($attrs);
        if ($attrs === '') {
            return null;
        }

        if (preg_match_all(
            '/([a-zA-Z_][\w-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))/',
            $attrs,
            $pairs,
            PREG_SET_ORDER,
        )) {
            foreach ($pairs as $pair) {
                $key = strtolower($pair[1]);
                $value = ($pair[2] ?? '') !== '' ? $pair[2] : (($pair[3] ?? '') !== '' ? $pair[3] : ($pair[4] ?? ''));
                if ($key === 'src' && $value !== '') {
                    return self::splitSrc($value);
                }
            }
        }

        return null;
    }

    private static function splitSrc(string $src): array
    {
        $parts = explode('|', $src);
        return array_values(array_filter(
            array_map(static fn($p) => trim($p), $parts),
            static fn($p) => $p !== '',
        ));
    }
}
