<?php

use App\Support\RuntimeLogTargets;

function runtimeLogServer(string $uuid, string $name, array $containers): array
{
    return [
        'uuid' => $uuid,
        'name' => $name,
        'containers' => $containers,
    ];
}

function runtimeLogContainer(string $name, string $labels, string $state = 'running', string $id = ''): array
{
    return [
        'ID' => $id !== '' ? $id : $name,
        'Names' => $name,
        'State' => $state,
        'Labels' => $labels,
    ];
}

it('orders production ahead of newer previews', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app-pr-7', 'coolify.pullRequestId=7,com.docker.compose.service=web'),
            runtimeLogContainer('app', 'coolify.pullRequestId=0,com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-42', 'coolify.pullRequestId=42,com.docker.compose.service=web'),
        ]),
    ], '', '0', '', '', true);

    expect($targets['deployments'])->toBe([
        ['value' => '0', 'label' => 'Production'],
        ['value' => '42', 'label' => 'Preview #42'],
        ['value' => '7', 'label' => 'Preview #7'],
    ])
        ->and($targets['deployment'])->toBe('0')
        ->and($targets['container'])->toBe('app')
        ->and($targets['service'])->toBe('');
});

it('treats a missing pull request label as production', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app', 'com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-3', 'coolify.pullRequestId=3,com.docker.compose.service=web'),
        ]),
    ], '', '0', '', '', true);

    expect($targets['deployments'][0]['label'])->toBe('Production')
        ->and($targets['container'])->toBe('app');
});

it('uses the preview from the query when that deployment exists', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app', 'coolify.pullRequestId=0,com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-12', 'coolify.pullRequestId=12,com.docker.compose.service=web'),
        ]),
    ], '', '12', '', '', true);

    expect($targets['deployment'])->toBe('12')
        ->and($targets['container'])->toBe('app-pr-12');
});

it('falls back to production when the requested preview is gone', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app', 'coolify.pullRequestId=0,com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-7', 'coolify.pullRequestId=7,com.docker.compose.service=web'),
        ]),
    ], '', '99', '', '', true);

    expect($targets['deployment'])->toBe('0')
        ->and($targets['container'])->toBe('app');
});

it('falls back to the newest preview when production has no container', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app-pr-3', 'coolify.pullRequestId=3,com.docker.compose.service=web'),
            runtimeLogContainer('app-pr-12', 'coolify.pullRequestId=12,com.docker.compose.service=web'),
        ]),
    ], '', '0', '', '', true);

    expect($targets['deployment'])->toBe('12')
        ->and($targets['container'])->toBe('app-pr-12');
});

it('names services from the compose label, then coolify.name, then the container headline', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('compose-name', 'com.docker.compose.service=api,coolify.name=ignored'),
            runtimeLogContainer('labeled-name', 'coolify.name=worker'),
            runtimeLogContainer('plain-container-1', ''),
        ]),
    ], '', '0', '', '', false);

    expect(array_column($targets['services'], 'label'))->toBe(['api', 'Plain Container', 'worker'])
        ->and($targets['container'])->toBe('compose-name');
});

it('sorts services alphabetically and keeps a running container ahead of a stopped one', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('web-old', 'com.docker.compose.service=web', 'exited', 'web-old-id'),
            runtimeLogContainer('web-new', 'com.docker.compose.service=web', 'running', 'web-new-id'),
            runtimeLogContainer('api', 'com.docker.compose.service=api', 'exited', 'api-id'),
        ]),
    ], '', '0', '', '', true);

    expect($targets['services'])->toBe([
        ['value' => 'api', 'label' => 'api', 'description' => 'exited'],
        ['value' => 'web-new', 'label' => 'web (running)', 'description' => 'running'],
        ['value' => 'web-old', 'label' => 'web (exited)', 'description' => 'exited'],
    ])
        ->and($targets['container'])->toBe('web-new')
        ->and($targets['service'])->toBe('web-new');
});

it('selects the running container when several share the requested service', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('web-old', 'com.docker.compose.service=web', 'exited'),
            runtimeLogContainer('web-new', 'com.docker.compose.service=web', 'running'),
        ]),
    ], '', '0', 'web', '', true);

    expect($targets['container'])->toBe('web-new')
        ->and($targets['service'])->toBe('web-new');
});

it('keeps the requested stopped container when its name is passed', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('web-old', 'com.docker.compose.service=web', 'exited'),
            runtimeLogContainer('web-new', 'com.docker.compose.service=web', 'running'),
        ]),
    ], '', '0', 'web', 'web-old', true);

    expect($targets['container'])->toBe('web-old')
        ->and($targets['service'])->toBe('web-old');
});

it('adds the container id when two containers would get the same label', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('web-a', 'com.docker.compose.service=web', 'exited', 'aaaa11112222'),
            runtimeLogContainer('web-b', 'com.docker.compose.service=web', 'exited', 'bbbb11112222'),
        ]),
    ], '', '0', '', '', true);

    expect(array_column($targets['services'], 'label'))->toBe([
        'web (exited, aaaa11112222)',
        'web (exited, bbbb11112222)',
    ]);
});

it('offers a server list only from servers that have containers and keeps a requested server', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('primary-app', 'coolify.pullRequestId=0'),
        ]),
        runtimeLogServer('edge', 'Edge', [
            runtimeLogContainer('edge-app', 'coolify.pullRequestId=0'),
        ]),
    ], 'edge', '0', '', '', true);

    expect($targets['servers'])->toBe([
        ['value' => 'primary', 'label' => 'Primary'],
        ['value' => 'edge', 'label' => 'Edge'],
    ])
        ->and($targets['server'])->toBe('edge')
        ->and($targets['container'])->toBe('edge-app');
});

it('uses the first server when the requested server is unknown', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('primary-app', 'coolify.pullRequestId=0'),
        ]),
        runtimeLogServer('edge', 'Edge', [
            runtimeLogContainer('edge-app', 'coolify.pullRequestId=0'),
        ]),
    ], 'missing', '0', '', '', true);

    expect($targets['server'])->toBe('primary')
        ->and($targets['container'])->toBe('primary-app');
});

it('opens a preview link on the server that is running that preview', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('primary-app', 'coolify.pullRequestId=0'),
        ]),
        runtimeLogServer('edge', 'Edge', [
            runtimeLogContainer('edge-pr-8', 'coolify.pullRequestId=8'),
        ]),
    ], '', '8', '', '', true);

    expect($targets['server'])->toBe('edge')
        ->and($targets['deployment'])->toBe('8')
        ->and($targets['container'])->toBe('edge-pr-8');
});

it('stays on the requested server when that server does not have the preview', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('primary-app', 'coolify.pullRequestId=0'),
        ]),
        runtimeLogServer('edge', 'Edge', [
            runtimeLogContainer('edge-pr-8', 'coolify.pullRequestId=8'),
        ]),
    ], 'primary', '8', '', '', true);

    expect($targets['server'])->toBe('primary')
        ->and($targets['deployment'])->toBe('0')
        ->and($targets['container'])->toBe('primary-app');
});

it('does not split one-click services into preview deployments', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('app-service', 'coolify.pullRequestId=4,com.docker.compose.service=app'),
            runtimeLogContainer('db-service', 'coolify.pullRequestId=4,com.docker.compose.service=db'),
        ]),
    ], '', '4', '', '', false);

    expect($targets['deployments'])->toBe([])
        ->and($targets['deployment'])->toBe('0')
        ->and(array_column($targets['services'], 'label'))->toBe(['app', 'db']);
});

it('reads labels given as an array', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            [
                'ID' => 'app-id',
                'Names' => 'app',
                'State' => 'running',
                'Labels' => [
                    'coolify.pullRequestId' => '0',
                    'com.docker.compose.service' => 'web',
                ],
            ],
        ]),
    ], '', '0', '', '', true);

    expect($targets['container'])->toBe('app')
        ->and($targets['services'][0]['label'])->toBe('web');
});

it('drops containers whose names cannot be passed to docker logs', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', [
            runtimeLogContainer('valid-name', 'com.docker.compose.service=web'),
            runtimeLogContainer('bad name', 'com.docker.compose.service=api'),
        ]),
    ], '', '0', '', '', true);

    expect(array_column($targets['services'], 'value'))->toBe(['valid-name']);
});

it('returns an empty selection when nothing is running', function () {
    $targets = RuntimeLogTargets::select([
        runtimeLogServer('primary', 'Primary', []),
    ], 'primary', '12', 'web', 'web', true);

    expect($targets['server'])->toBe('')
        ->and($targets['container'])->toBe('')
        ->and($targets['servers'])->toBe([]);
});
