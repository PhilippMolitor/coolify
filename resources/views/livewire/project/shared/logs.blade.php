<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Logs | Coolify
    </x-slot>

    @if ($type === 'application')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.application.heading :application="$resource" wire:key="application-heading-logs" />
    @elseif ($type === 'database')
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" :query="$query"
            title="Logs" />
    @endif

    @php
        $totalContainers = collect($serverContainers)->flatten(1)->count();
        $functionalServers = $servers->filter(fn ($server) => $server->isFunctional());
        $logsUnavailable = $containersLoaded && (
            $servers->isEmpty()
            || $functionalServers->isEmpty()
            || $totalContainers === 0
        );
        $logTargets = $targets ?? null;
        $selectedLogServer = is_array($logTargets)
            ? $servers->firstWhere('uuid', $logTargets['server'] ?? null)
            : null;
    @endphp

    @if (in_array($type, ['application', 'database', 'service'], true))
        <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
            <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
                @if ($type === 'application')
                    <x-application.configuration-sidebar :application="$resource" current-route="project.application.logs" />
                @elseif ($type === 'database')
                    <x-database.configuration-sidebar :database="$resource" current-route="project.database.logs" />
                @else
                    <x-service.configuration-sidebar :service="$resource" current-route="project.service.logs" />
                @endif
                <div class="min-w-0">
    @endif

    <div wire:loading.block wire:target="loadAllContainers" class="hidden w-full">
        <x-skeleton.logs />
    </div>

    <div class="w-full" x-init="if (! $wire.containersLoaded) { $wire.loadAllContainers() }"
        wire:loading.remove wire:target="loadAllContainers">
        @if (! $containersLoaded)
            <x-skeleton.logs />
        @elseif ($logsUnavailable)
            @if ($servers->isEmpty())
                <x-empty size="lg" title="Runtime logs unavailable"
                    description="Connect and validate a server before viewing resource logs."
                    icon-name="file-content" />
            @elseif ($functionalServers->isEmpty())
                <x-empty size="lg" title="Runtime logs unavailable"
                    description="No functional servers are available, so container logs cannot be loaded."
                    icon-name="file-content" />
            @elseif (count($serverErrors) > 0)
                <x-empty size="lg" title="Runtime logs unavailable"
                    description="Could not load containers: {{ collect($serverErrors)->first() }}"
                    icon-name="file-content" />
            @else
                <x-empty size="lg" title="Runtime logs unavailable"
                    description="No containers are running, so there are no runtime logs to show."
                    icon-name="file-content" />
            @endif
        @else
            <div class="runtime-logs-workspace">
                @foreach ($servers as $serverModel)
                    @if (! $serverModel->isFunctional())
                        <x-callout type="warning" title="Server unavailable">
                            {{ $serverModel->name }} is not functional, so its container logs cannot be loaded.
                        </x-callout>
                    @elseif (isset($serverErrors[$serverModel->id]))
                        <x-callout type="warning" title="Could not load containers">
                            {{ $serverModel->name }}: {{ $serverErrors[$serverModel->id] }}
                        </x-callout>
                    @endif
                @endforeach

                @if ($selectedLogServer && filled($logTargets['container'] ?? null))
                    @if (count($logTargets['servers']) > 1 || count($logTargets['deployments']) > 1 || count($logTargets['services']) > 1)
                        <div class="flex shrink-0 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                            @if (count($logTargets['servers']) > 1)
                                <div class="w-full sm:w-60" wire:key="runtime-logs-servers-{{ collect($logTargets['servers'])->map(fn (array $option): string => $option['value'].$option['label'])->join('|') }}">
                                    <x-forms.listbox id="server" label="Server" live searchable portal
                                        searchPlaceholder="Search servers" :options="$logTargets['servers']" />
                                </div>
                            @endif
                            @if (count($logTargets['deployments']) > 1)
                                <div class="w-full sm:w-60" wire:key="runtime-logs-deployments-{{ collect($logTargets['deployments'])->pluck('value')->join('-') }}">
                                    <x-forms.listbox id="pullRequestId" label="Deployment" live searchable portal
                                        searchPlaceholder="Search deployments" :options="$logTargets['deployments']" />
                                </div>
                            @endif
                            @if (count($logTargets['services']) > 1)
                                <div class="w-full sm:w-60" wire:key="runtime-logs-services-{{ collect($logTargets['services'])->map(fn (array $option): string => $option['value'].$option['label'].$option['description'])->join('|') }}">
                                    <x-forms.listbox id="selectedContainer" label="Service" live searchable portal
                                        searchPlaceholder="Search services" :options="$logTargets['services']" />
                                </div>
                            @endif
                        </div>
                    @endif

                    <livewire:project.shared.get-logs
                        wire:key="runtime-logs-{{ $logTargets['containerId'] }}"
                        :server="$selectedLogServer"
                        :resource="$resource"
                        :container="$logTargets['container']"
                        :collapsible="false"
                        :fill="true" />
                @else
                    <x-empty size="lg" title="Runtime logs unavailable"
                        description="No containers are running, so there are no runtime logs to show."
                        icon-name="file-content" />
                @endif
            </div>
        @endif
    </div>
    @if (in_array($type, ['application', 'database', 'service'], true))
                </div>
            </div>
        </section>
    @endif
</div>
