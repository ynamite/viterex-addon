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
}
