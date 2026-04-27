<?php

namespace Ynamite\ViteRex;

use rex;
use rex_extension_point;

final class OutputFilter
{
    /**
     * `OUTPUT_FILTER` callback. Frontend-only — backend bails to avoid rewriting
     * literal "REX_VITE" strings that appear in admin UIs (e.g., the slice editor).
     * For the block_peek preview iframe, see the `BLOCK_PEEK_OUTPUT` handler in boot.php
     * which calls `rewriteHtml()` directly (preview is rendered inside a backend request).
     */
    public static function register(rex_extension_point $ep): void
    {
        if (rex::isBackend()) {
            return;
        }
        $content = $ep->getSubject();
        if (!is_string($content) || $content === '') {
            return;
        }
        $ep->setSubject(self::rewriteHtml($content));
    }

    /**
     * Pure HTML transformer. Replaces `REX_VITE[src="…"]` placeholders with the
     * rendered asset block; if the document contains no placeholder at all, auto-inserts
     * the block before the first `</head>`. Reusable across contexts (frontend OUTPUT_FILTER,
     * block_peek preview, etc.).
     */
    public static function rewriteHtml(string $content): string
    {
        if ($content === '') {
            return $content;
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
            return $content;
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

        return $replaced;
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
