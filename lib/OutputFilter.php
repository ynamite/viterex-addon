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
     * Pure HTML transformer. Replaces the **first** `REX_VITE` (or `REX_VITE[src="…"]`)
     * placeholder inside the **first `<head>...</head>` block** with the rendered asset
     * block. If the head contains no placeholder, the block is auto-inserted before the
     * closing `</head>`. Body content is never rewritten — literal `REX_VITE` inside
     * `<code>`, `<pre>`, slice text, etc. is preserved.
     *
     * Reusable across contexts (frontend OUTPUT_FILTER, block_peek preview, etc.).
     */
    public static function rewriteHtml(string $content): string
    {
        return self::rewriteHtmlWithBlock($content, [Assets::class, 'renderBlock']);
    }

    /**
     * @internal Exposed for unit testing — the public entrypoint is {@see rewriteHtml()}.
     *
     * @param callable(?array<int,string>): string $renderBlock Called with the parsed
     *     entries from a `REX_VITE[src="…"]` attribute, or `null` for a bare `REX_VITE`
     *     placeholder (use default entries) or for the auto-insert path.
     */
    public static function rewriteHtmlWithBlock(string $content, callable $renderBlock): string
    {
        if ($content === '') {
            return $content;
        }

        if (!preg_match('/<head\b[^>]*>.*?<\/head>/is', $content, $headMatch, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $headStart = $headMatch[0][1];
        $head      = $headMatch[0][0];
        $headLen   = strlen($head);

        $matchCount = 0;
        $newHead = preg_replace_callback(
            '/(?<!\w)REX_VITE(?:\[([^\]]*)\])?/',
            static function (array $matches) use ($renderBlock): string {
                $attrs = $matches[1] ?? '';
                return $renderBlock(self::parseEntries($attrs));
            },
            $head,
            1,
            $matchCount,
        );

        if ($newHead === null) {
            return $content;
        }

        if ($matchCount === 0) {
            $block = $renderBlock(null);
            if ($block !== '') {
                $autoInserted = preg_replace(
                    '/<\/head>/i',
                    $block . "\n</head>",
                    $newHead,
                    1,
                    $autoCount,
                );
                if ($autoInserted !== null && $autoCount === 1) {
                    $newHead = $autoInserted;
                }
            }
        }

        if ($newHead === $head) {
            return $content;
        }

        return substr_replace($content, $newHead, $headStart, $headLen);
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
