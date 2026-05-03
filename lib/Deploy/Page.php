<?php

namespace Ynamite\ViteRex\Deploy;

final class Page
{
    public const STATE_NO_SIDECAR = 'no_sidecar';
    public const STATE_NEEDS_ACTIVATION = 'needs_activation';
    public const STATE_ACTIVE = 'active';
    public const STATE_MISSING_DEPLOY_FILE = 'missing_deploy_file';

    /**
     * @param array<string,mixed>|null $sidecar
     */
    public static function detectState(?array $sidecar, ?string $deployContents): string
    {
        if ($deployContents === null) {
            return self::STATE_MISSING_DEPLOY_FILE;
        }
        if ($sidecar === null) {
            return self::STATE_NO_SIDECAR;
        }
        return DeployFile::hasMarkers($deployContents) ? self::STATE_ACTIVE : self::STATE_NEEDS_ACTIVATION;
    }

    /**
     * Build the canonical sidecar array from a posted form payload, or report
     * validation errors. `errors` is a flat list of human-readable messages
     * (the page renders them as a list); `cfg` is null iff there are errors.
     *
     * @param array<string,mixed> $post Expected shape: ['repository' => string, 'hosts' => array<int, array<string,string>>]
     * @return array{cfg: ?array{repository: string, hosts: list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>}, errors: list<string>}
     */
    public static function validatePost(array $post): array
    {
        $errors = [];

        $repository = trim((string) ($post['repository'] ?? ''));
        if ($repository === '') {
            $errors[] = 'repository is required';
        }

        $rawHosts = $post['hosts'] ?? [];
        if (!is_array($rawHosts) || count($rawHosts) === 0) {
            $errors[] = 'at least one host is required';
            return ['cfg' => null, 'errors' => $errors];
        }

        $cfgHosts = [];
        $seenNames = [];
        foreach ($rawHosts as $i => $raw) {
            $line = $i + 1;
            if (!is_array($raw)) {
                $errors[] = "host #{$line}: invalid payload";
                continue;
            }
            $name = trim((string) ($raw['name'] ?? ''));
            $hostname = trim((string) ($raw['hostname'] ?? ''));
            $user = trim((string) ($raw['user'] ?? ''));
            $stage = trim((string) ($raw['stage'] ?? ''));
            $path = trim((string) ($raw['path'] ?? ''));
            $portRaw = trim((string) ($raw['port'] ?? ''));

            foreach (['name' => $name, 'hostname' => $hostname, 'user' => $user, 'path' => $path] as $field => $val) {
                if ($val === '') {
                    $errors[] = "host #{$line}: {$field} is required";
                }
            }
            if ($stage === '') {
                $stage = $name;
            }

            $port = null;
            if ($portRaw !== '') {
                if (!ctype_digit($portRaw)) {
                    $errors[] = "host #{$line}: port must be an integer or empty";
                } else {
                    $port = (int) $portRaw;
                }
            }

            if ($name !== '') {
                if (isset($seenNames[$name])) {
                    $errors[] = "host #{$line}: duplicate host name '{$name}'";
                }
                $seenNames[$name] = true;
            }

            $cfgHosts[] = [
                'name' => $name,
                'hostname' => $hostname,
                'port' => $port,
                'user' => $user,
                'stage' => $stage,
                'path' => $path,
            ];
        }

        if ($errors !== []) {
            return ['cfg' => null, 'errors' => $errors];
        }
        return [
            'cfg' => ['repository' => $repository, 'hosts' => $cfgHosts],
            'errors' => [],
        ];
    }
}
