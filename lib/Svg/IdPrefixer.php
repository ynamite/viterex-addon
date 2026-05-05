<?php

namespace Ynamite\ViteRex\Svg;

/**
 * Scope-isolates SVG fragments by prefixing every `id`, `class`, and internal
 * reference with a per-file prefix. Without this, two SVGs inlined into the
 * same HTML page collide on `.cls-1`-style selectors (their `<style>` blocks
 * have document-level scope when inlined into HTML), and any `<use href="#x">`
 * / `url(#x)` / `<filter id="x">` references can cross-link between SVGs or
 * silently break.
 *
 * Applied at `Assets::inline()` runtime only — disk files stay generic so
 * they remain reusable as `<img src>` / `background-image: url()`.
 *
 * What gets rewritten:
 *
 *   - `id="X"` (both quote styles) → `id="<prefix>-X"`
 *   - `class="X Y Z"` (any quote style) → `class="<prefix>-X <prefix>-Y <prefix>-Z"`
 *   - `url(#X)` everywhere (attrs, inline `style`, inside `<style>`) → `url(#<prefix>-X)`
 *   - `href="#X"` / `xlink:href="#X"` (fragment-only) → with prefix; full URLs untouched
 *   - inside `<style>` blocks: `.X` class selectors (always) and `#X` id
 *     selectors but only when X matches an actual `id="X"` attribute in the
 *     SVG (filter avoids hex colour literals like `#fff` / `#abc` which can't
 *     be reliably distinguished from id selectors via regex alone)
 *
 * Not handled (rare in icon-style SVGs; documented as known limits):
 *
 *   - CSS `@media` / `@supports` / `@keyframes` blocks with their own nested
 *     selectors (the simple `<style>` body scan rewrites every `.X` it sees,
 *     which is usually fine but can over-prefix rules that target host CSS)
 *   - SMIL `from`/`to` references to other element IDs
 *   - References inside `<foreignObject>` HTML
 *
 * Idempotency: the prefixer relies on `<prefix>-` being unique per file and
 * not present in original ids/classes. Re-running on already-prefixed output
 * WILL double-prefix — `Assets::inline()` caches the result and never re-runs
 * on the same input/path pair.
 */
final class IdPrefixer
{
    public function prefix(string $svg, string $prefix): string
    {
        if ($svg === '' || $prefix === '') {
            return $svg;
        }

        // Collect IDs declared as attributes. Used as a filter when rewriting
        // `#X` selectors inside <style> — without this, hex colours like
        // `#fff` would be misidentified as id selectors and get prefixed.
        $idSet = [];
        if (preg_match_all('/\bid\s*=\s*["\']([^"\']+)["\']/i', $svg, $m)) {
            $idSet = array_flip(array_unique($m[1]));
        }

        // Rewrite <style> bodies first. Doing this before the global url()
        // pass means url() inside <style> still gets rewritten (step 4) and
        // we never risk re-prefixing already-prefixed selectors.
        $svg = preg_replace_callback(
            '/(<style\b[^>]*>)(.*?)(<\/style>)/is',
            function (array $m) use ($prefix, $idSet): string {
                $body = preg_replace_callback(
                    '/\.([A-Za-z_-][\w-]*)/',
                    static fn(array $mm): string => '.' . $prefix . '-' . $mm[1],
                    $m[2],
                );
                if ($idSet !== []) {
                    $body = preg_replace_callback(
                        '/#([A-Za-z_-][\w-]*)/',
                        static fn(array $mm): string => isset($idSet[$mm[1]])
                            ? '#' . $prefix . '-' . $mm[1]
                            : $mm[0],
                        (string) $body,
                    );
                }
                return $m[1] . ((string) $body) . $m[3];
            },
            $svg,
        ) ?? $svg;

        // id="X" / id='X'
        $svg = preg_replace_callback(
            '/\bid\s*=\s*"([^"]+)"/i',
            static fn(array $m): string => 'id="' . $prefix . '-' . $m[1] . '"',
            $svg,
        ) ?? $svg;
        $svg = preg_replace_callback(
            "/\bid\s*=\s*'([^']+)'/i",
            static fn(array $m): string => "id='" . $prefix . '-' . $m[1] . "'",
            $svg,
        ) ?? $svg;

        // class="X Y Z" / class='X Y Z'
        $svg = preg_replace_callback(
            '/\bclass\s*=\s*"([^"]+)"/i',
            fn(array $m): string => 'class="' . self::prefixTokens($m[1], $prefix) . '"',
            $svg,
        ) ?? $svg;
        $svg = preg_replace_callback(
            "/\bclass\s*=\s*'([^']+)'/i",
            fn(array $m): string => "class='" . self::prefixTokens($m[1], $prefix) . "'",
            $svg,
        ) ?? $svg;

        // url(#X) — fill="url(#x)", filter="url(#x)", style="…url(#x)…",
        // and inside <style> rule bodies. One pass covers all three.
        $svg = preg_replace_callback(
            '/url\(\s*#([^\s)]+)\s*\)/i',
            static fn(array $m): string => 'url(#' . $prefix . '-' . $m[1] . ')',
            $svg,
        ) ?? $svg;

        // href="#X" and xlink:href="#X" — fragment-only refs only. Full URLs
        // (`https://…`, `page.html#anchor`) are left alone.
        $svg = preg_replace_callback(
            '/\b(xlink:href|href)\s*=\s*"#([^"]+)"/i',
            static fn(array $m): string => $m[1] . '="#' . $prefix . '-' . $m[2] . '"',
            $svg,
        ) ?? $svg;
        $svg = preg_replace_callback(
            "/\b(xlink:href|href)\s*=\s*'#([^']+)'/i",
            static fn(array $m): string => $m[1] . "='#" . $prefix . '-' . $m[2] . "'",
            $svg,
        ) ?? $svg;

        return $svg;
    }

    /**
     * Magic-comment opt-out. SVGs containing `<!-- viterex:no-prefix -->`
     * anywhere in the document skip the prefixer entirely. Useful for SVGs
     * that intentionally rely on shared id/class names across inlines (e.g.,
     * a sprite-style icon set with cross-document references).
     */
    public function isOptedOut(string $svg): bool
    {
        return (bool) preg_match('/<!--\s*viterex:no-prefix\s*-->/', $svg);
    }

    /**
     * Derive a stable, unique prefix from a relative file path. Letters and
     * digits are preserved; everything else collapses to a single hyphen.
     * Always carries a `viterex-` namespace so prefixed names cannot collide
     * with the host page's own classes/ids.
     *
     *   img/icon-foo.svg     → viterex-img-icon-foo
     *   logo.svg             → viterex-logo
     *   img/brand/logo-2.svg → viterex-img-brand-logo-2
     */
    public function deriveStablePrefix(string $relativePath): string
    {
        $stem = (string) preg_replace('/\.svg$/i', '', $relativePath);
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $stem));
        $slug = trim($slug, '-');
        return 'viterex-' . ($slug !== '' ? $slug : 'svg');
    }

    private static function prefixTokens(string $value, string $prefix): string
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $prefixed = array_map(
            static fn(string $c): string => $c === '' ? '' : $prefix . '-' . $c,
            $tokens,
        );
        return implode(' ', $prefixed);
    }
}
