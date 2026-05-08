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
 *   - `class="X Y Z"` (any quote style) → tokens are prefixed only when they
 *     also appear as class selectors inside the SVG's own `<style>` block;
 *     external classes (Tailwind utilities, project CSS, BEM) pass through
 *     unchanged so host-page CSS continues to match them
 *   - `url(#X)` everywhere (attrs, inline `style`, inside `<style>`) → `url(#<prefix>-X)`
 *   - `href="#X"` / `xlink:href="#X"` (fragment-only) → with prefix; full URLs untouched
 *   - inside `<style>` blocks: `.X` class selectors (always) and `#X` id
 *     selectors but only when X matches an actual `id="X"` attribute in the
 *     SVG (filter avoids hex colour literals like `#fff` / `#abc` which can't
 *     be reliably distinguished from id selectors via regex alone)
 *
 * Not handled (rare in icon-style SVGs; documented as known limits):
 *
 *   - CSS `@media` / `@supports` / `@keyframes` body scanning is shallow:
 *     every `.X` inside `<style>` is treated as a defined class, even if
 *     the selector targets a host-page class rather than an SVG-local one
 *   - Attribute selectors like `[class~="foo"]` are not parsed; classes
 *     reachable only through them must also be defined via a plain `.foo`
 *     rule for the auto-scope to pick them up
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
    public const VERSION = 2;

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

        // Collect classes declared inside <style>. Used to filter `class="..."`
        // token rewriting — only tokens defined locally get prefixed; Tailwind
        // utilities and other host-styled classes pass through untouched.
        $classSet = self::collectDefinedClasses($svg);

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
            fn(array $m): string => 'class="' . self::prefixTokens($m[1], $prefix, $classSet) . '"',
            $svg,
        ) ?? $svg;
        $svg = preg_replace_callback(
            "/\bclass\s*=\s*'([^']+)'/i",
            fn(array $m): string => "class='" . self::prefixTokens($m[1], $prefix, $classSet) . "'",
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
     *
     *   img/icon-foo.svg     → img-icon-foo
     *   logo.svg             → logo
     *   img/brand/logo-2.svg → img-brand-logo-2
     */
    public function deriveStablePrefix(string $relativePath): string
    {
        $stem = (string) preg_replace('/\.svg$/i', '', $relativePath);
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $stem));
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'svg';
    }

    private static function prefixTokens(string $value, string $prefix, array $definedClasses): string
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $prefixed = array_map(
            static fn(string $c): string => ($c === '' || !isset($definedClasses[$c]))
                ? $c
                : $prefix . '-' . $c,
            $tokens,
        );
        return implode(' ', $prefixed);
    }

    /**
     * Walk every <style>...</style> block and collect class names that appear
     * as selectors. Used by prefixTokens() to skip externally-defined utility
     * classes (Tailwind, project CSS) and only rewrite locally-defined ones.
     */
    private static function collectDefinedClasses(string $svg): array
    {
        $set = [];
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $svg, $blocks)) {
            foreach ($blocks[1] as $body) {
                if (preg_match_all('/\.([A-Za-z_-][\w-]*)/', $body, $names)) {
                    foreach ($names[1] as $name) {
                        $set[$name] = true;
                    }
                }
            }
        }
        return $set;
    }
}
