<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Groups running and stopped containers for the runtime logs page.
 *
 * Servers stay in the order they are given (the resource's primary server first).
 * Deployments are production, then preview pull requests from newest to oldest.
 * Services are alphabetical, with a running container before a stopped one of the same name.
 */
class RuntimeLogTargets
{
    /**
     * @param  list<array{uuid: string, name: string, containers: list<array<string, mixed>>}>  $servers
     * @return array{
     *     servers: list<array{value: string, label: string}>,
     *     deployments: list<array{value: string, label: string}>,
     *     services: list<array{value: string, label: string, description: string}>,
     *     server: string,
     *     deployment: string,
     *     service: string,
     *     container: string,
     *     containerId: string,
     * }
     */
    public static function select(
        array $servers,
        string $server,
        string $deployment,
        string $service,
        string $container,
        bool $groupDeployments,
    ): array {
        $servers = self::normalizeServers($servers, $groupDeployments);
        if ($servers === []) {
            return self::emptySelection();
        }

        $preferredDeployment = self::normalizeDeployment($deployment);
        $chosenServer = self::pickServer($servers, $server, $preferredDeployment, $groupDeployments);
        $deployments = $groupDeployments ? self::deploymentOptions($chosenServer['containers']) : [];
        $deploymentIds = array_column($deployments, 'value');
        $chosenDeployment = self::pickDeployment($deploymentIds, $preferredDeployment);

        $containers = array_values(array_filter(
            $chosenServer['containers'],
            fn (array $item): bool => $item['deployment'] === $chosenDeployment,
        ));
        if ($containers === []) {
            return self::emptySelection();
        }
        usort($containers, self::compareServices(...));

        $chosen = self::pickContainer($containers, $service, $container);
        $services = self::serviceOptions($containers);

        return [
            'servers' => array_map(fn (array $item): array => [
                'value' => $item['uuid'],
                'label' => $item['name'],
            ], $servers),
            'deployments' => $deployments,
            'services' => $services,
            'server' => $chosenServer['uuid'],
            'deployment' => $chosenDeployment,
            'service' => self::serviceParameter($containers, $chosen),
            'container' => $chosen['name'],
            'containerId' => $chosen['id'],
        ];
    }

    /**
     * @return array{
     *     servers: list<empty>,
     *     deployments: list<empty>,
     *     services: list<empty>,
     *     server: string,
     *     deployment: string,
     *     service: string,
     *     container: string,
     *     containerId: string,
     * }
     */
    private static function emptySelection(): array
    {
        return [
            'servers' => [],
            'deployments' => [],
            'services' => [],
            'server' => '',
            'deployment' => '0',
            'service' => '',
            'container' => '',
            'containerId' => '',
        ];
    }

    /**
     * @param  list<array{uuid: string, name: string, containers: list<array<string, mixed>>}>  $servers
     * @return list<array{uuid: string, name: string, containers: list<array{name: string, id: string, key: string, state: string, deployment: string}>}>
     */
    private static function normalizeServers(array $servers, bool $groupDeployments): array
    {
        $normalized = [];
        foreach ($servers as $server) {
            $containers = [];
            foreach ($server['containers'] ?? [] as $container) {
                if (! is_array($container)) {
                    continue;
                }
                $name = (string) data_get($container, 'Names', '');
                if (! ValidationPatterns::isValidContainerName($name)) {
                    continue;
                }
                $labels = self::labels($container);
                $state = strtolower(trim((string) data_get($container, 'State', 'running')));
                $containers[] = [
                    'name' => $name,
                    'id' => (string) (data_get($container, 'ID') ?: $name),
                    'key' => self::serviceKey($name, $labels),
                    'state' => $state !== '' ? $state : 'running',
                    'deployment' => self::deploymentId($labels, $groupDeployments),
                ];
            }
            if ($containers === []) {
                continue;
            }
            $normalized[] = [
                'uuid' => (string) ($server['uuid'] ?? ''),
                'name' => (string) ($server['name'] ?? ''),
                'containers' => $containers,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $container
     */
    private static function labels(array $container): Collection
    {
        $raw = $container['Labels'] ?? null;
        if (is_array($raw)) {
            return collect($raw);
        }
        if (! is_string($raw) || trim($raw) === '') {
            return collect();
        }

        $parsed = format_docker_labels_to_json($raw);

        return $parsed instanceof Collection ? $parsed : collect();
    }

    private static function serviceKey(string $containerName, Collection $labels): string
    {
        $compose = trim((string) $labels->get('com.docker.compose.service', ''));
        if ($compose !== '') {
            return $compose;
        }

        $name = trim((string) $labels->get('coolify.name', ''));
        if ($name !== '') {
            return $name;
        }

        $headline = str($containerName)->beforeLast('-')->headline()->toString();

        return $headline !== '' ? $headline : $containerName;
    }

    private static function deploymentId(Collection $labels, bool $groupDeployments): string
    {
        if (! $groupDeployments) {
            return '0';
        }

        return self::normalizeDeployment((string) $labels->get('coolify.pullRequestId', ''));
    }

    private static function normalizeDeployment(string $deployment): string
    {
        if ($deployment === '' || ! ctype_digit($deployment) || (int) $deployment === 0) {
            return '0';
        }

        return (string) (int) $deployment;
    }

    /**
     * @param  list<array{uuid: string, name: string, containers: list<array{deployment: string}>}>  $servers
     * @return array{uuid: string, name: string, containers: list<array{name: string, id: string, key: string, state: string, deployment: string}>}
     */
    private static function pickServer(array $servers, string $requestedServer, string $preferredDeployment, bool $groupDeployments): array
    {
        if ($requestedServer !== '') {
            foreach ($servers as $server) {
                if ($server['uuid'] === $requestedServer) {
                    return $server;
                }
            }
        }

        if ($groupDeployments && $preferredDeployment !== '0') {
            foreach ($servers as $server) {
                foreach ($server['containers'] as $container) {
                    if ($container['deployment'] === $preferredDeployment) {
                        return $server;
                    }
                }
            }
        }

        return $servers[0];
    }

    /**
     * @param  list<array{deployment: string}>  $containers
     * @return list<array{value: string, label: string}>
     */
    private static function deploymentOptions(array $containers): array
    {
        $ids = array_values(array_unique(array_column($containers, 'deployment')));
        usort($ids, function (string $left, string $right): int {
            if ($left === '0') {
                return -1;
            }
            if ($right === '0') {
                return 1;
            }

            return (int) $right <=> (int) $left;
        });

        return array_map(fn (string $id): array => [
            'value' => $id,
            'label' => $id === '0' ? 'Production' : 'Preview #'.$id,
        ], $ids);
    }

    /**
     * @param  list<string>  $deploymentIds
     */
    private static function pickDeployment(array $deploymentIds, string $preferred): string
    {
        if (in_array($preferred, $deploymentIds, true)) {
            return $preferred;
        }
        if (in_array('0', $deploymentIds, true)) {
            return '0';
        }

        return $deploymentIds[0] ?? '0';
    }

    /**
     * @param  array{key: string, state: string, name: string}  $left
     * @param  array{key: string, state: string, name: string}  $right
     */
    private static function compareServices(array $left, array $right): int
    {
        $byKey = strcasecmp($left['key'], $right['key']);
        if ($byKey !== 0) {
            return $byKey;
        }

        $byState = ($left['state'] === 'running' ? 0 : 1) <=> ($right['state'] === 'running' ? 0 : 1);
        if ($byState !== 0) {
            return $byState;
        }

        return strcmp($left['name'], $right['name']);
    }

    /**
     * @param  list<array{name: string, id: string, key: string, state: string}>  $containers
     * @return array{name: string, id: string, key: string, state: string}
     */
    private static function pickContainer(array $containers, string $service, string $container): array
    {
        if ($container !== '') {
            foreach ($containers as $item) {
                if ($item['name'] === $container) {
                    return $item;
                }
            }
        }

        if ($service !== '') {
            $byName = null;
            $byKey = null;
            $byRunningKey = null;
            foreach ($containers as $item) {
                if ($byName === null && $item['name'] === $service) {
                    $byName = $item;
                }
                if ($item['key'] === $service) {
                    $byKey ??= $item;
                    if ($byRunningKey === null && $item['state'] === 'running') {
                        $byRunningKey = $item;
                    }
                }
            }
            if ($byName !== null) {
                return $byName;
            }
            if ($byRunningKey !== null) {
                return $byRunningKey;
            }
            if ($byKey !== null) {
                return $byKey;
            }
        }

        foreach ($containers as $item) {
            if ($item['state'] === 'running') {
                return $item;
            }
        }

        return $containers[0];
    }

    /**
     * @param  list<array{name: string, id: string, key: string, state: string}>  $containers
     * @return list<array{value: string, label: string, description: string}>
     */
    private static function serviceOptions(array $containers): array
    {
        $keyCounts = [];
        foreach ($containers as $container) {
            $keyCounts[$container['key']] = ($keyCounts[$container['key']] ?? 0) + 1;
        }

        $labels = [];
        foreach ($containers as $container) {
            $label = $keyCounts[$container['key']] > 1
                ? $container['key'].' ('.$container['state'].')'
                : $container['key'];
            $labels[] = $label;
        }

        $labelCounts = array_count_values($labels);
        $options = [];
        foreach ($containers as $index => $container) {
            $label = $labels[$index];
            if ($labelCounts[$label] > 1) {
                $short = substr($container['id'], 0, 12);
                $label = $keyCounts[$container['key']] > 1
                    ? $container['key'].' ('.$container['state'].', '.$short.')'
                    : $container['key'].' ('.$short.')';
            }
            $options[] = [
                'value' => $container['name'],
                'label' => $label,
                'description' => $container['state'],
            ];
        }

        return $options;
    }

    /**
     * @param  list<array{name: string, key: string}>  $containers
     * @param  array{name: string, key: string}  $chosen
     */
    private static function serviceParameter(array $containers, array $chosen): string
    {
        if (count($containers) < 2) {
            return '';
        }

        $matches = 0;
        foreach ($containers as $container) {
            if ($container['key'] === $chosen['key']) {
                $matches++;
            }
        }

        return $matches > 1 ? $chosen['name'] : $chosen['key'];
    }
}
