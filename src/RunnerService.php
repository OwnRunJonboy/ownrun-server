<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class RunnerService
{
    public function __construct(private PDO $db) {}

    /**
     * @param list<string>|null $models
     * @param list<string>|null $agentProfiles
     * @param list<string>|null $agentModels
     * @param list<string>|null $agentProjects
     * @param string|null $timezone IANA name from the runner
     * @param bool|null $ollamaWebSearch Runner reports search is configured
     * @param list<string>|null $agentToolIds Full runner tool catalog
     * @param string|null $backendState active_ollama|active_sd|switching|failed
     * @param bool|null $sdAvailable
     * @param list<string>|null $sdModels
     * @param bool|null $ollamaModelsStale
     */
    public function heartbeat(
        int $runnerId,
        ?array $models = null,
        ?array $agentProfiles = null,
        ?array $agentModels = null,
        ?array $agentProjects = null,
        ?string $timezone = null,
        ?bool $ollamaWebSearch = null,
        ?array $agentToolIds = null,
        ?string $backendState = null,
        ?bool $sdAvailable = null,
        ?array $sdModels = null,
        ?bool $ollamaModelsStale = null,
    ): void {
        $sets = ['last_seen_at = NOW()'];
        $params = [];

        if ($models !== null) {
            $sets[] = 'available_models = ?';
            $params[] = json_encode(array_values($models));
        }
        if ($agentProfiles !== null) {
            $sets[] = 'agent_profiles = ?';
            $params[] = json_encode(array_values($agentProfiles));
        }
        if ($agentModels !== null) {
            $sets[] = 'agent_models = ?';
            $params[] = json_encode(array_values($agentModels));
        }
        if ($agentProjects !== null) {
            $sets[] = 'agent_projects = ?';
            $params[] = json_encode(array_values($agentProjects));
        }
        if ($timezone !== null) {
            $tz = trim($timezone);
            $sets[] = 'timezone = ?';
            $params[] = $tz !== '' ? $tz : null;
        }
        if ($ollamaWebSearch !== null) {
            $sets[] = 'ollama_web_search = ?';
            $params[] = $ollamaWebSearch ? 1 : 0;
        }
        if ($agentToolIds !== null) {
            $sets[] = 'agent_tool_ids = ?';
            $params[] = json_encode(array_values($agentToolIds));
        }
        if ($backendState !== null) {
            $allowed = ['active_ollama', 'active_sd', 'switching', 'failed', ''];
            $state = trim($backendState);
            if (!in_array($state, $allowed, true)) {
                $state = '';
            }
            $sets[] = 'backend_state = ?';
            $params[] = $state !== '' ? $state : null;
        }
        if ($sdAvailable !== null) {
            $sets[] = 'sd_available = ?';
            $params[] = $sdAvailable ? 1 : 0;
        }
        if ($sdModels !== null) {
            $sets[] = 'sd_models = ?';
            $params[] = json_encode(array_values($sdModels));
        }
        if ($ollamaModelsStale !== null) {
            $sets[] = 'ollama_models_stale = ?';
            $params[] = $ollamaModelsStale ? 1 : 0;
        }

        $params[] = $runnerId;
        $sql = 'UPDATE runners SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);
    }

    /**
     * @return array{
     *   models: list<string>,
     *   runner_online: bool,
     *   runner_last_seen_at: ?string,
     *   agent_profiles: list<string>,
     *   agent_models: list<string>,
     *   agent_projects: list<string>,
     *   timezone: string,
     *   web_search_available: bool,
     *   agent_tool_ids: list<string>,
     *   backend_state: ?string,
     *   sd_available: bool,
     *   sd_models: list<string>,
     *   ollama_models_stale: bool
     * }
     */
    public function status(): array
    {
        $window = max(0, (int) Config::get('runner_online_window', 60));
        $stmt = $this->db->query(
            "SELECT last_seen_at, available_models, agent_profiles, agent_models, agent_projects,
                    timezone, ollama_web_search, agent_tool_ids,
                    backend_state, sd_available, sd_models, ollama_models_stale,
                    (last_seen_at IS NOT NULL
                     AND last_seen_at >= DATE_SUB(NOW(), INTERVAL {$window} SECOND)) AS online
             FROM runners WHERE enabled = 1 ORDER BY last_seen_at DESC LIMIT 1"
        );
        $row = $stmt->fetch();

        $models = [];
        $agentProfiles = [];
        $agentModels = [];
        $agentProjects = [];
        $agentToolIds = ToolPolicyService::catalog();
        $timezone = 'America/New_York';
        $lastSeen = null;
        $online = false;
        $webSearchAvailable = false;
        $backendState = null;
        $sdAvailable = false;
        $sdModels = [];
        $ollamaModelsStale = false;
        if ($row) {
            $lastSeen = $row['last_seen_at'];
            $online = (int) $row['online'] === 1;
            $models = $this->decodeStringList($row['available_models'] ?? null);
            $agentProfiles = $this->decodeStringList($row['agent_profiles'] ?? null);
            $agentModels = $this->decodeStringList($row['agent_models'] ?? null);
            $agentProjects = $this->decodeStringList($row['agent_projects'] ?? null);
            $fromRunner = $this->decodeStringList($row['agent_tool_ids'] ?? null);
            if ($fromRunner !== []) {
                $agentToolIds = $fromRunner;
            }
            $tz = trim((string) ($row['timezone'] ?? ''));
            if ($tz !== '') {
                $timezone = $tz;
            }
            if ($row['ollama_web_search'] !== null) {
                $webSearchAvailable = (int) $row['ollama_web_search'] === 1;
            }
            $bs = trim((string) ($row['backend_state'] ?? ''));
            $backendState = $bs !== '' ? $bs : null;
            if ($row['sd_available'] !== null) {
                $sdAvailable = (int) $row['sd_available'] === 1;
            }
            $sdModels = $this->decodeStringList($row['sd_models'] ?? null);
            if ($row['ollama_models_stale'] !== null) {
                $ollamaModelsStale = (int) $row['ollama_models_stale'] === 1;
            }
        }

        return [
            'models' => $models,
            'runner_online' => $online,
            'runner_last_seen_at' => $lastSeen,
            'agent_profiles' => $agentProfiles,
            'agent_models' => $agentModels,
            'agent_projects' => $agentProjects,
            'timezone' => $timezone,
            'web_search_available' => $webSearchAvailable,
            'agent_tool_ids' => $agentToolIds,
            'backend_state' => $backendState,
            'sd_available' => $sdAvailable,
            'sd_models' => $sdModels,
            'ollama_models_stale' => $ollamaModelsStale,
        ];
    }

    /** @return list<string> */
    private function decodeStringList(mixed $raw): array
    {
        if (!$raw) {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map('strval', $decoded));
    }
}
