<?php

namespace Ynamite\ViteRex\Deploy;

use rex_path;

final class DeployFile
{
    public const MARKER_OPEN = '// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>';
    public const MARKER_CLOSE = '// <<< VITEREX:DEPLOY_CONFIG <<<';

    public static function path(): string
    {
        return rex_path::base('deploy.php');
    }

    public static function hasMarkers(string $contents): bool
    {
        $tokens = @token_get_all($contents);
        if (!is_array($tokens)) {
            return false;
        }
        $opens = 0;
        $closes = 0;
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            // Only count markers that appear as PHP comments — string literals
            // are T_CONSTANT_ENCAPSED_STRING and never counted here.
            if ($token[0] !== T_COMMENT) {
                continue;
            }
            $line = $token[1];
            if (str_contains($line, self::MARKER_OPEN)) {
                $opens++;
            }
            if (str_contains($line, self::MARKER_CLOSE)) {
                $closes++;
            }
        }
        return $opens === 1 && $closes === 1;
    }

    private const HOST_METHOD_MAP = [
        'setHostname' => 'hostname',
        'setRemoteUser' => 'user',
        'setPort' => 'port',
        'setDeployPath' => 'path',
    ];

    private const PROLOGUE_VAR_MAP = [
        'deploymentName' => 'name',
        'deploymentHost' => 'hostname',
        'deploymentPort' => 'port',
        'deploymentUser' => 'user',
        'deploymentType' => 'stage',
        'deploymentPath' => 'path',
        'deploymentRepository' => 'repository',
    ];

    /**
     * @return array{repository: string, hosts: list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>}|null
     */
    public static function extract(string $contents): ?array
    {
        $tokens = @token_get_all($contents);
        if (!is_array($tokens) || count($tokens) === 0) {
            return null;
        }
        // Strip whitespace + comments. Also strip T_INLINE_HTML so a leading
        // BOM (which lands in T_INLINE_HTML before T_OPEN_TAG) doesn't trip
        // the parser at offset 0.
        $sig = [];
        foreach ($tokens as $i => $tok) {
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML], true)) {
                continue;
            }
            $sig[] = ['orig_index' => $i, 'tok' => $tok];
        }

        $prologue = self::extractPrologue($sig);
        $hosts = self::extractHosts($sig, $prologue);
        $repository = $prologue['repository'] ?? null;

        if ($repository === null || !is_string($repository) || count($hosts) === 0) {
            return null;
        }
        return ['repository' => $repository, 'hosts' => $hosts];
    }

    /**
     * Walk significant tokens; collect $deployment* = 'literal'; assignments
     * regardless of where they appear at the top level. Returns map var-suffix
     * → string value (e.g., 'name' => 'staging', 'port' => '22', 'repository' => '…').
     *
     * @param list<array{orig_index:int, tok: mixed}> $sig
     * @return array<string,string>
     */
    private static function extractPrologue(array $sig): array
    {
        $vars = [];
        $n = count($sig);
        for ($i = 0; $i < $n - 3; $i++) {
            $a = $sig[$i]['tok'];
            $b = $sig[$i + 1]['tok'];
            $c = $sig[$i + 2]['tok'];
            $d = $sig[$i + 3]['tok'];
            if (!is_array($a) || $a[0] !== T_VARIABLE) {
                continue;
            }
            $varName = ltrim($a[1], '$');
            if (!isset(self::PROLOGUE_VAR_MAP[$varName])) {
                continue;
            }
            if ($b !== '=') {
                continue;
            }
            if (!is_array($c) || $c[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if ($d !== ';') {
                continue;
            }
            $vars[self::PROLOGUE_VAR_MAP[$varName]] = self::unquote($c[1]);
        }
        return $vars;
    }

    /**
     * Walk significant tokens; for each `host(arg)->setX(arg)->...->setY(arg);`
     * chain at top level, build a host array. Skip chains that don't include
     * setHostname + setDeployPath (the minimum to be recognizable).
     *
     * @param list<array{orig_index:int, tok: mixed}> $sig
     * @param array<string,string> $prologue
     * @return list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>
     */
    private static function extractHosts(array $sig, array $prologue): array
    {
        $hosts = [];
        $n = count($sig);
        $i = 0;
        while ($i < $n - 2) {
            $a = $sig[$i]['tok'];
            $b = $sig[$i + 1]['tok'];
            // looking for: T_STRING("host") '('
            if (!is_array($a) || $a[0] !== T_STRING || $a[1] !== 'host' || $b !== '(') {
                $i++;
                continue;
            }
            $arg = $sig[$i + 2]['tok'];
            $closeParen = $sig[$i + 3]['tok'] ?? null;
            if ($closeParen !== ')') {
                $i++;
                continue;
            }
            $hostName = self::resolveScalar($arg, $prologue, 'name');
            if ($hostName === null) {
                $i++;
                continue;
            }
            // walk method chain from index i+4
            $j = $i + 4;
            $collected = ['name' => $hostName];
            $endIdx = $j;
            while ($j < $n) {
                $tok = $sig[$j]['tok'];
                if ($tok === ';') {
                    $endIdx = $j;
                    break;
                }
                if (!is_array($tok) || $tok[0] !== T_OBJECT_OPERATOR) {
                    // not a method chain continuation — bail without consuming
                    $j = -1;
                    break;
                }
                $methodTok = $sig[$j + 1]['tok'] ?? null;
                $openTok = $sig[$j + 2]['tok'] ?? null;
                if (!is_array($methodTok) || $methodTok[0] !== T_STRING || $openTok !== '(') {
                    $j = -1;
                    break;
                }
                $method = $methodTok[1];
                // For 'set' with first arg 'labels' and second arg ['stage' => X],
                // capture stage. Otherwise it's a known setX() with one scalar arg.
                if ($method === 'set') {
                    [$consumed, $stage] = self::parseSetLabelsCall($sig, $j + 2, $prologue);
                    if ($consumed === 0) {
                        // not a labels call we recognize — skip past this method's parens
                        $consumed = self::skipBalancedParens($sig, $j + 2);
                        if ($consumed === 0) {
                            $j = -1;
                            break;
                        }
                    } else {
                        $collected['stage'] = $stage;
                    }
                    $j = $j + 2 + $consumed;
                    continue;
                }
                if (!isset(self::HOST_METHOD_MAP[$method])) {
                    // unrecognized method (e.g., setLabels or anything else) — bail
                    $j = -1;
                    break;
                }
                $argTok = $sig[$j + 3]['tok'] ?? null;
                $closeTok = $sig[$j + 4]['tok'] ?? null;
                if ($closeTok !== ')') {
                    $j = -1;
                    break;
                }
                $key = self::HOST_METHOD_MAP[$method];
                $value = self::resolveScalar($argTok, $prologue, $key);
                if ($value === null) {
                    $j = -1;
                    break;
                }
                $collected[$key] = $value;
                $j += 5;
            }
            if ($j < 0) {
                $i++;
                continue;
            }
            // host chain must include hostname + path at minimum to count
            if (!isset($collected['hostname'], $collected['path'])) {
                $i++;
                continue;
            }
            $hosts[] = self::completeHost($collected);
            $i = $endIdx + 1;
        }
        return $hosts;
    }

    /**
     * Parse `('labels', ['stage' => X])`. Short-array form only — long-form
     * `array('stage' => X)` is not supported (Deployer 7.x examples and the
     * viterex-installer scaffold both use short arrays). Returns
     * [tokensConsumedFromOpenParen, stageValueOrNull]. tokensConsumed=0 means
     * "not a labels call we recognize" (caller should fall back to skipping).
     *
     * Token layout expected (positions relative to $openIdx):
     *   0:( 1:'labels' 2:, 3:[ 4:'stage' 5:=> 6:X 7:] 8:)  → 9 tokens
     *
     * @param list<array{orig_index:int, tok: mixed}> $sig
     * @param array<string,string> $prologue
     * @return array{0:int, 1:string|null}
     */
    private static function parseSetLabelsCall(array $sig, int $openIdx, array $prologue): array
    {
        $get = static fn(int $idx) => $sig[$idx]['tok'] ?? null;

        if ($get($openIdx) !== '(') return [0, null];

        $first = $get($openIdx + 1);
        if (!is_array($first) || $first[0] !== T_CONSTANT_ENCAPSED_STRING
            || self::unquote($first[1]) !== 'labels'
        ) {
            return [0, null];
        }
        if ($get($openIdx + 2) !== ',') return [0, null];
        if ($get($openIdx + 3) !== '[') return [0, null];

        $keyTok = $get($openIdx + 4);
        $arrowTok = $get($openIdx + 5);
        $valTok = $get($openIdx + 6);
        if ($get($openIdx + 7) !== ']') return [0, null];
        if ($get($openIdx + 8) !== ')') return [0, null];

        if (!is_array($keyTok) || $keyTok[0] !== T_CONSTANT_ENCAPSED_STRING
            || self::unquote($keyTok[1]) !== 'stage'
        ) {
            return [0, null];
        }
        if (!is_array($arrowTok) || $arrowTok[0] !== T_DOUBLE_ARROW) {
            return [0, null];
        }

        $stage = self::resolveScalar($valTok, $prologue, 'stage');
        if ($stage === null) return [0, null];

        return [9, (string) $stage];
    }

    /**
     * Skip past one balanced parentheses pair starting at $openIdx (which must
     * point at '('). Returns the number of tokens consumed including both
     * parens, or 0 if not balanced.
     *
     * @param list<array{orig_index:int, tok: mixed}> $sig
     */
    private static function skipBalancedParens(array $sig, int $openIdx): int
    {
        if (($sig[$openIdx]['tok'] ?? null) !== '(') {
            return 0;
        }
        $depth = 1;
        $n = count($sig);
        for ($k = $openIdx + 1; $k < $n; $k++) {
            $t = $sig[$k]['tok'];
            if ($t === '(') $depth++;
            elseif ($t === ')') {
                $depth--;
                if ($depth === 0) {
                    return $k - $openIdx + 1;
                }
            }
        }
        return 0;
    }

    /**
     * Resolve a token to its string value. Accepts string literal or a
     * $deploymentX variable resolvable from the prologue. For numeric fields
     * (port), returns int.
     *
     * @param mixed $tok
     * @param array<string,string> $prologue
     * @return string|int|null
     */
    private static function resolveScalar(mixed $tok, array $prologue, string $key): mixed
    {
        $value = null;
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = self::unquote($tok[1]);
        } elseif (is_array($tok) && $tok[0] === T_LNUMBER) {
            $value = (int) $tok[1];
        } elseif (is_array($tok) && $tok[0] === T_VARIABLE) {
            $varName = ltrim($tok[1], '$');
            $promotedKey = self::PROLOGUE_VAR_MAP[$varName] ?? null;
            if ($promotedKey !== null && isset($prologue[$promotedKey])) {
                $value = $prologue[$promotedKey];
            }
        }
        if ($value === null) {
            return null;
        }
        if ($key === 'port') {
            return is_int($value) ? $value : (int) $value;
        }
        return (string) $value;
    }

    private static function unquote(string $literal): string
    {
        // T_CONSTANT_ENCAPSED_STRING comes with surrounding quotes intact.
        // Single-quoted: handle \\ and \'. Double-quoted: handle the same plus a few escapes.
        $first = $literal[0] ?? '';
        $inner = substr($literal, 1, -1);
        if ($first === "'") {
            return strtr($inner, ['\\\\' => '\\', "\\'" => "'"]);
        }
        // double-quoted
        return strtr($inner, ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\t' => "\t"]);
    }

    /**
     * @param array<string,mixed> $collected
     * @return array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}
     */
    private static function completeHost(array $collected): array
    {
        return [
            'name' => (string) $collected['name'],
            'hostname' => (string) $collected['hostname'],
            'port' => isset($collected['port']) ? (int) $collected['port'] : null,
            'user' => (string) ($collected['user'] ?? ''),
            'stage' => (string) ($collected['stage'] ?? ''),
            'path' => (string) $collected['path'],
        ];
    }
}
