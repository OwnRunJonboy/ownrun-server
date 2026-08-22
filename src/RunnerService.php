<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class RunnerService
{
    public function __construct(private PDO $db) {}

    /** @param list<string>|null $models */
    public function heartbeat(int $runnerId, ?array $models = null): void
    {
        if ($models !== null) {
            $encoded = json_encode(array_values($models));
            $this->db->prepare(
                'UPDATE runners SET last_seen_at = NOW(), available_models = ? WHERE id = ?'
            )->execute([$encoded, $runnerId]);
        } else {
            $this->db->prepare(
                'UPDATE runners SET last_seen_at = NOW() WHERE id = ?'
            )->execute([$runnerId]);
        }
    }

    /** @return array{models: list<string>, runner_online: bool, runner_last_seen_at: ?string} */
    public function status(): array
    {
        $window = max(0, (int) Config::get('runner_online_window', 60));
        $stmt = $this->db->query(
            "SELECT last_seen_at, available_models,
                    (last_seen_at IS NOT NULL
                     AND last_seen_at >= DATE_SUB(NOW(), INTERVAL {$window} SECOND)) AS online
             FROM runners WHERE enabled = 1 ORDER BY last_seen_at DESC LIMIT 1"
        );
        $row = $stmt->fetch();

        $models = [];
        $lastSeen = null;
        $online = false;
        if ($row) {
            $lastSeen = $row['last_seen_at'];
            $online = (int) $row['online'] === 1;
            if ($row['available_models']) {
                $decoded = json_decode($row['available_models'], true);
                if (is_array($decoded)) {
                    $models = $decoded;
                }
            }
        }

        return [
            'models' => $models,
            'runner_online' => $online,
            'runner_last_seen_at' => $lastSeen,
        ];
    }
}
