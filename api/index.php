<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'OwnRun\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use OwnRun\AuthService;
use OwnRun\CalendarService;
use OwnRun\Config;
use OwnRun\Database;
use OwnRun\JobService;
use OwnRun\JournalService;
use OwnRun\Request;
use OwnRun\Response;
use OwnRun\Router;
use OwnRun\RunnerService;

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    Response::error('Server not configured. Copy config.example.php to config.php.', 503);
    exit;
}

try {
    Config::load($configPath);
    $db = Database::connect();
} catch (\Throwable $e) {
    Response::error('Server configuration error', 503);
    exit;
}

$auth = new AuthService($db);
$jobs = new JobService($db);
$runners = new RunnerService($db);
$calendar = new CalendarService($db);
$journal = new JournalService($db);
$router = new Router();

$runnerTimezone = static function () use ($runners): string {
    return $runners->status()['timezone'] ?? JournalService::DEFAULT_TIMEZONE;
};

$requireUser = function () use ($auth): int {
    $userId = $auth->validateAccessToken(Request::bearerToken());
    if ($userId === null) {
        Response::error('Unauthorized', 401);
        exit;
    }
    return $userId;
};

$requireRunner = function () use ($auth): int {
    $runnerId = $auth->validateRunnerToken(Request::bearerToken());
    if ($runnerId === null) {
        Response::error('Unauthorized', 401);
        exit;
    }
    return $runnerId;
};

// Auth
$router->add('POST', '/auth/login', function () use ($auth): void {
    $body = Request::jsonBody();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $rememberMe = (bool) ($body['remember_me'] ?? false);

    if ($username === '' || $password === '') {
        Response::error('Username and password required', 422);
        return;
    }

    $tokens = $auth->login($username, $password, $rememberMe);
    if ($tokens === null) {
        Response::error('Invalid credentials', 401);
        return;
    }
    Response::json($tokens);
});

$router->add('POST', '/auth/refresh', function () use ($auth): void {
    $body = Request::jsonBody();
    $refreshToken = (string) ($body['refresh_token'] ?? '');
    if ($refreshToken === '') {
        Response::error('Refresh token required', 422);
        return;
    }

    $tokens = $auth->refresh($refreshToken);
    if ($tokens === null) {
        Response::error('Invalid or expired refresh token', 401);
        return;
    }
    Response::json($tokens);
});

$router->add('POST', '/auth/logout', function () use ($auth): void {
    $body = Request::jsonBody();
    $refreshToken = (string) ($body['refresh_token'] ?? '');
    if ($refreshToken !== '') {
        $auth->logout($refreshToken);
    }
    Response::json(['ok' => true]);
});

// Jobs (web)
$router->add('GET', '/jobs', function () use ($jobs, $requireUser): void {
    $userId = $requireUser();
    Response::json(['jobs' => $jobs->listForUser($userId)]);
});

$router->add('POST', '/jobs', function () use ($jobs, $requireUser): void {
    $userId = $requireUser();
    $body = Request::jsonBody();
    $type = trim((string) ($body['type'] ?? 'ollama.chat'));
    $model = trim((string) ($body['model'] ?? ''));
    $prompt = trim((string) ($body['prompt'] ?? ''));
    $systemPrompt = isset($body['system_prompt']) ? trim((string) $body['system_prompt']) : null;

    if ($model === '' || $prompt === '') {
        Response::error('Model and prompt are required', 422);
        return;
    }

    try {
        if ($type === 'agent.task') {
            $profile = trim((string) ($body['profile'] ?? ''));
            if ($profile === '') {
                Response::error('Profile is required for agent jobs', 422);
                return;
            }
            $maxIter = null;
            if (array_key_exists('max_tool_iterations', $body) && $body['max_tool_iterations'] !== null) {
                $maxIter = (int) $body['max_tool_iterations'];
            }
            $project = isset($body['project']) ? trim((string) $body['project']) : null;
            $job = $jobs->createAgent(
                $userId,
                $model,
                $prompt,
                $profile,
                $systemPrompt,
                $maxIter,
                $project,
            );
        } elseif ($type === 'ollama.chat') {
            $job = $jobs->create($userId, $model, $prompt, $systemPrompt);
        } else {
            Response::error('Unsupported job type', 422);
            return;
        }
        Response::json($job, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('GET', '/jobs/{id}', function (array $params) use ($jobs, $requireUser): void {
    $userId = $requireUser();
    $job = $jobs->getForUser($userId, (int) $params['id']);
    if ($job === null) {
        Response::error('Job not found', 404);
        return;
    }
    Response::json($job);
});

$router->add('POST', '/jobs/{id}/cancel', function (array $params) use ($jobs, $requireUser): void {
    $userId = $requireUser();
    $job = $jobs->cancel($userId, (int) $params['id']);
    if ($job === null) {
        Response::error('Job not found or cannot be cancelled', 409);
        return;
    }
    Response::json($job);
});

$router->add('POST', '/jobs/{id}/requeue', function (array $params) use ($jobs, $requireUser): void {
    $userId = $requireUser();
    $job = $jobs->requeue($userId, (int) $params['id']);
    if ($job === null) {
        Response::error('Job not found or cannot be requeued', 409);
        return;
    }
    Response::json($job);
});

$router->add('POST', '/jobs/{id}/continue', function (array $params) use ($jobs, $requireUser): void {
    $userId = $requireUser();
    $body = Request::jsonBody();
    $prompt = trim((string) ($body['prompt'] ?? ''));
    if ($prompt === '') {
        Response::error('Prompt is required', 422);
        return;
    }

    try {
        $job = $jobs->continueFrom($userId, (int) $params['id'], $prompt);
        if ($job === null) {
            Response::error('Job not found', 404);
            return;
        }
        Response::json($job, 201);
    } catch (\DomainException $e) {
        Response::error($e->getMessage(), 409);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

// Models / runner status (web)
$router->add('GET', '/models', function () use ($runners, $calendar, $journal, $requireUser): void {
    $userId = $requireUser();
    $status = $runners->status();
    $fromCalendar = $calendar->listProjectsForUser($userId);
    $mergedCal = array_values(array_unique(array_merge($status['agent_projects'], $fromCalendar)));
    sort($mergedCal, SORT_NATURAL | SORT_FLAG_CASE);
    $status['calendar_projects'] = $mergedCal;

    $fromJournal = $journal->listProjectsForUser($userId);
    $mergedJournal = array_values(array_unique(array_merge($status['agent_projects'], $fromJournal)));
    sort($mergedJournal, SORT_NATURAL | SORT_FLAG_CASE);
    $status['journal_projects'] = $mergedJournal;

    Response::json($status);
});

$notFoundAgent = static function (\RuntimeException $e): bool {
    if ($e->getMessage() === 'not_found') {
        Response::error('Not found', 404);
        return true;
    }
    return false;
};

$forbiddenAgent = static function (\RuntimeException $e): bool {
    if ($e->getMessage() === 'forbidden') {
        Response::error('Journal writes require a project on this job', 403);
        return true;
    }
    return false;
};

// Calendar (web)
$router->add('GET', '/calendar', function () use ($calendar, $requireUser): void {
    $userId = $requireUser();
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $projectSpecified = array_key_exists('project', $_GET);
    $project = $projectSpecified ? (string) $_GET['project'] : null;
    try {
        Response::json($calendar->listForUser($userId, $from, $to, $project, $projectSpecified));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('GET', '/calendar/{id}', function (array $params) use ($calendar, $requireUser): void {
    $userId = $requireUser();
    $entry = $calendar->getForUser($userId, (int) $params['id']);
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('POST', '/calendar', function () use ($calendar, $requireUser): void {
    $userId = $requireUser();
    try {
        $entry = $calendar->createForUser($userId, Request::jsonBody());
        Response::json($entry, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('PATCH', '/calendar/{id}', function (array $params) use ($calendar, $requireUser): void {
    $userId = $requireUser();
    try {
        $entry = $calendar->updateForUser($userId, (int) $params['id'], Request::jsonBody());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('POST', '/calendar/{id}', function (array $params) use ($calendar, $requireUser): void {
    $userId = $requireUser();
    try {
        $entry = $calendar->updateForUser($userId, (int) $params['id'], Request::jsonBody());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('DELETE', '/calendar/{id}', function (array $params) use ($calendar, $requireUser): void {
    $userId = $requireUser();
    if (!$calendar->deleteForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

$router->add('POST', '/calendar/{id}/delete', function (array $params) use ($calendar, $requireUser): void {
    $userId = $requireUser();
    if (!$calendar->deleteForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

// Journal (web) — register /memory before /{id}
$router->add('GET', '/journal/memory', function () use ($journal, $requireUser, $runnerTimezone): void {
    $userId = $requireUser();
    Response::json($journal->memoryForUser($userId, $runnerTimezone()));
});

$router->add('GET', '/journal', function () use ($journal, $requireUser): void {
    $userId = $requireUser();
    $projectSpecified = array_key_exists('project', $_GET);
    $project = $projectSpecified ? (string) $_GET['project'] : null;
    $q = isset($_GET['q']) ? (string) $_GET['q'] : null;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : JournalService::WEB_LIST_DEFAULT;
    $beforeDate = isset($_GET['before_date']) ? (string) $_GET['before_date'] : null;
    $beforeId = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;
    if (!isset($_GET['before_id'])) {
        $beforeId = null;
    }
    $from = isset($_GET['from']) ? (string) $_GET['from'] : null;
    $to = isset($_GET['to']) ? (string) $_GET['to'] : null;
    try {
        Response::json($journal->listForUser(
            $userId,
            $project,
            $projectSpecified,
            $q,
            $limit,
            $beforeDate,
            $beforeId,
            $from,
            $to,
        ));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('GET', '/journal/{id}', function (array $params) use ($journal, $requireUser): void {
    $userId = $requireUser();
    $entry = $journal->getForUser($userId, (int) $params['id']);
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('POST', '/journal', function () use ($journal, $requireUser, $runnerTimezone): void {
    $userId = $requireUser();
    try {
        $entry = $journal->createForUser($userId, Request::jsonBody(), $runnerTimezone());
        Response::json($entry, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('PATCH', '/journal/{id}', function (array $params) use ($journal, $requireUser, $runnerTimezone): void {
    $userId = $requireUser();
    try {
        $entry = $journal->updateForUser($userId, (int) $params['id'], Request::jsonBody(), $runnerTimezone());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('POST', '/journal/{id}', function (array $params) use ($journal, $requireUser, $runnerTimezone): void {
    $userId = $requireUser();
    try {
        $entry = $journal->updateForUser($userId, (int) $params['id'], Request::jsonBody(), $runnerTimezone());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('DELETE', '/journal/{id}', function (array $params) use ($journal, $requireUser): void {
    $userId = $requireUser();
    if (!$journal->deleteForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

$router->add('POST', '/journal/{id}/delete', function (array $params) use ($journal, $requireUser): void {
    $userId = $requireUser();
    if (!$journal->deleteForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

// Runner endpoints
$router->add('POST', '/runner/heartbeat', function () use ($runners, $requireRunner): void {
    $runnerId = $requireRunner();
    $body = Request::jsonBody();
    $models = null;
    if (isset($body['available_models']) && is_array($body['available_models'])) {
        $models = array_map('strval', $body['available_models']);
    }
    $agentProfiles = null;
    if (isset($body['agent_profiles']) && is_array($body['agent_profiles'])) {
        $agentProfiles = array_map('strval', $body['agent_profiles']);
    }
    $agentModels = null;
    if (isset($body['agent_models']) && is_array($body['agent_models'])) {
        $agentModels = array_map('strval', $body['agent_models']);
    }
    $agentProjects = null;
    if (isset($body['agent_projects']) && is_array($body['agent_projects'])) {
        $agentProjects = array_map('strval', $body['agent_projects']);
    }
    $timezone = null;
    if (isset($body['timezone']) && is_string($body['timezone'])) {
        $timezone = $body['timezone'];
    }
    $runners->heartbeat($runnerId, $models, $agentProfiles, $agentModels, $agentProjects, $timezone);
    Response::json(['ok' => true]);
});

$router->add('POST', '/runner/jobs/claim', function () use ($jobs, $requireRunner): void {
    $runnerId = $requireRunner();
    $job = $jobs->claim($runnerId);
    if ($job === null) {
        Response::json(['job' => null]);
        return;
    }
    Response::json(['job' => $job]);
});

$router->add('POST', '/runner/jobs/{id}/heartbeat', function (array $params) use ($jobs, $requireRunner): void {
    $runnerId = $requireRunner();
    $result = $jobs->heartbeat($runnerId, (int) $params['id']);
    if ($result === null) {
        Response::error('Job not found or not owned by this runner', 404);
        return;
    }
    Response::json(['ok' => true, 'cancel_requested' => (bool) $result['cancel_requested']]);
});

$router->add('POST', '/runner/jobs/{id}/progress', function (array $params) use ($jobs, $requireRunner): void {
    $runnerId = $requireRunner();
    $body = Request::jsonBody();
    $result = $body['result'] ?? null;
    if (!is_array($result)) {
        Response::error('Result payload required', 422);
        return;
    }
    try {
        $ok = $jobs->progress($runnerId, (int) $params['id'], $result);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if (!$ok) {
        Response::error('Job not found or not running', 404);
        return;
    }
    Response::json(['ok' => true]);
});

$router->add('POST', '/runner/jobs/{id}/complete', function (array $params) use ($jobs, $requireRunner): void {
    $runnerId = $requireRunner();
    $body = Request::jsonBody();
    $result = $body['result'] ?? null;
    if (!is_array($result)) {
        Response::error('Result payload required', 422);
        return;
    }
    try {
        $ok = $jobs->complete($runnerId, (int) $params['id'], $result);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if (!$ok) {
        // Cancelled while finishing — ignore rather than failing the runner upload loop.
        Response::json(['ok' => false, 'ignored' => true]);
        return;
    }
    Response::json(['ok' => true]);
});

$router->add('POST', '/runner/jobs/{id}/fail', function (array $params) use ($jobs, $requireRunner): void {
    $runnerId = $requireRunner();
    $body = Request::jsonBody();
    $message = trim((string) ($body['error_message'] ?? 'Job failed'));
    $ok = $jobs->fail($runnerId, (int) $params['id'], $message);
    if (!$ok) {
        Response::json(['ok' => false, 'ignored' => true]);
        return;
    }
    Response::json(['ok' => true]);
});

$router->add('GET', '/runner/jobs/{id}/calendar', function (array $params) use ($calendar, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $q = isset($_GET['q']) ? (string) $_GET['q'] : null;
    try {
        Response::json($calendar->listForAgent($runnerId, (int) $params['id'], $from, $to, $q));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
    }
});

$router->add('GET', '/runner/jobs/{id}/calendar/{entryId}', function (array $params) use ($calendar, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $entry = $calendar->getForAgent($runnerId, (int) $params['id'], (int) $params['entryId']);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('POST', '/runner/jobs/{id}/calendar', function (array $params) use ($calendar, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $entry = $calendar->createForAgent($runnerId, (int) $params['id'], Request::jsonBody());
        Response::json($entry, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
    }
});

$router->add('PATCH', '/runner/jobs/{id}/calendar/{entryId}', function (array $params) use ($calendar, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $entry = $calendar->updateForAgent(
            $runnerId,
            (int) $params['id'],
            (int) $params['entryId'],
            Request::jsonBody(),
        );
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('POST', '/runner/jobs/{id}/calendar/{entryId}', function (array $params) use ($calendar, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $entry = $calendar->updateForAgent(
            $runnerId,
            (int) $params['id'],
            (int) $params['entryId'],
            Request::jsonBody(),
        );
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('DELETE', '/runner/jobs/{id}/calendar/{entryId}', function (array $params) use ($calendar, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $ok = $calendar->deleteForAgent($runnerId, (int) $params['id'], (int) $params['entryId']);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if (!$ok) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['entryId']]);
});

$router->add('POST', '/runner/jobs/{id}/calendar/{entryId}/delete', function (array $params) use ($calendar, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $ok = $calendar->deleteForAgent($runnerId, (int) $params['id'], (int) $params['entryId']);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if (!$ok) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['entryId']]);
});

// Journal (runner)
$router->add('GET', '/runner/jobs/{id}/journal', function (array $params) use ($journal, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $q = isset($_GET['q']) ? (string) $_GET['q'] : null;
    try {
        Response::json($journal->listForAgent($runnerId, (int) $params['id'], $from, $to, $q));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
    }
});

$router->add('GET', '/runner/jobs/{id}/journal/{entryId}', function (array $params) use ($journal, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $entry = $journal->getForAgent($runnerId, (int) $params['id'], (int) $params['entryId']);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('POST', '/runner/jobs/{id}/journal', function (array $params) use ($journal, $requireRunner, $notFoundAgent, $forbiddenAgent, $runnerTimezone): void {
    $runnerId = $requireRunner();
    try {
        $entry = $journal->createForAgent($runnerId, (int) $params['id'], Request::jsonBody(), $runnerTimezone());
        Response::json($entry, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        if ($forbiddenAgent($e) || $notFoundAgent($e)) {
            return;
        }
        throw $e;
    }
});

$router->add('PATCH', '/runner/jobs/{id}/journal/{entryId}', function (array $params) use ($journal, $requireRunner, $notFoundAgent, $runnerTimezone): void {
    $runnerId = $requireRunner();
    try {
        $entry = $journal->updateForAgent(
            $runnerId,
            (int) $params['id'],
            (int) $params['entryId'],
            Request::jsonBody(),
            $runnerTimezone(),
        );
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('POST', '/runner/jobs/{id}/journal/{entryId}', function (array $params) use ($journal, $requireRunner, $notFoundAgent, $runnerTimezone): void {
    $runnerId = $requireRunner();
    try {
        $entry = $journal->updateForAgent(
            $runnerId,
            (int) $params['id'],
            (int) $params['entryId'],
            Request::jsonBody(),
            $runnerTimezone(),
        );
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if ($entry === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($entry);
});

$router->add('DELETE', '/runner/jobs/{id}/journal/{entryId}', function (array $params) use ($journal, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $ok = $journal->deleteForAgent($runnerId, (int) $params['id'], (int) $params['entryId']);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if (!$ok) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['entryId']]);
});

$router->add('POST', '/runner/jobs/{id}/journal/{entryId}/delete', function (array $params) use ($journal, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $ok = $journal->deleteForAgent($runnerId, (int) $params['id'], (int) $params['entryId']);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if (!$ok) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['entryId']]);
});

try {
    $router->dispatch(Request::method(), Request::path());
} catch (\Throwable $e) {
    error_log('OwnRun API error: ' . $e->getMessage());
    Response::error('Internal server error', 500);
}
