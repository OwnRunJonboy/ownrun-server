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
use OwnRun\ArtifactService;
use OwnRun\LoginThrottledException;
use OwnRun\CalendarService;
use OwnRun\Config;
use OwnRun\Database;
use OwnRun\JobService;
use OwnRun\JournalService;
use OwnRun\KnowledgeBaseService;
use OwnRun\Request;
use OwnRun\Response;
use OwnRun\Router;
use OwnRun\RunnerService;
use OwnRun\SystemPromptService;
use OwnRun\TaskService;
use OwnRun\ToolPolicyService;

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
$artifacts = new ArtifactService($db);
$calendar = new CalendarService($db);
$journal = new JournalService($db);
$systemPrompts = new SystemPromptService($db);
$tasks = new TaskService($db);
$toolPolicies = new ToolPolicyService($db);
$kb = new KnowledgeBaseService($db, $jobs);
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

$router->add('GET', '/health', function () use ($db): void {
    $db->query('SELECT 1');
    Response::json(['ok' => true]);
});

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

    try {
        $tokens = $auth->login($username, $password, $rememberMe, Request::clientIp());
    } catch (LoginThrottledException $e) {
        header('Retry-After: ' . (string) $e->retryAfterSeconds);
        Response::error($e->getMessage(), 429, ['retry_after' => $e->retryAfterSeconds]);
        return;
    }
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

$router->add('POST', '/jobs', function () use ($jobs, $toolPolicies, $requireUser): void {
    $userId = $requireUser();
    $body = Request::jsonBody();
    $type = trim((string) ($body['type'] ?? 'ollama.chat'));

    try {
        if ($type === 'stablediffusion.txt2img') {
            $job = $jobs->createTxt2Img($userId, $body);
            Response::json($job, 201);
            return;
        }

        $model = trim((string) ($body['model'] ?? ''));
        $prompt = trim((string) ($body['prompt'] ?? ''));
        $systemPrompt = isset($body['system_prompt']) ? trim((string) $body['system_prompt']) : null;

        if ($model === '' || $prompt === '') {
            Response::error('Model and prompt are required', 422);
            return;
        }

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
            try {
                $disabledTools = $toolPolicies->parseJobDisabledTools($body);
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 422);
                return;
            }
            $allowHttpFetch = false;
            if (array_key_exists('allow_http_fetch', $body)) {
                $allowHttpFetch = (bool) $body['allow_http_fetch'];
            }
            $job = $jobs->createAgent(
                $userId,
                $model,
                $prompt,
                $profile,
                $systemPrompt,
                $maxIter,
                $project,
                $disabledTools,
                $allowHttpFetch,
            );
        } elseif ($type === 'ollama.chat') {
            $project = isset($body['project']) ? trim((string) $body['project']) : null;
            $job = $jobs->create($userId, $model, $prompt, $systemPrompt, $project);
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

$router->add('GET', '/jobs/{id}/artifacts', function (array $params) use ($artifacts, $jobs, $requireUser): void {
    $userId = $requireUser();
    $jobId = (int) $params['id'];
    if ($jobs->getForUser($userId, $jobId) === null) {
        Response::error('Job not found', 404);
        return;
    }
    Response::json(['artifacts' => $artifacts->listForUser($userId, $jobId)]);
});

$router->add('GET', '/jobs/{id}/artifacts/{artifactId}', function (array $params) use ($artifacts, $requireUser): void {
    $userId = $requireUser();
    $file = $artifacts->absoluteFileForUser(
        $userId,
        (int) $params['id'],
        (int) $params['artifactId']
    );
    if ($file === null) {
        Response::error('Artifact not found', 404);
        return;
    }
    $download = isset($_GET['download']) && (string) $_GET['download'] !== '0';
    Response::file(
        $file['path'],
        $file['content_type'],
        $download ? $file['filename'] : null
    );
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
$router->add('GET', '/models', function () use ($runners, $calendar, $journal, $tasks, $kb, $toolPolicies, $requireUser): void {
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

    $fromTasks = $tasks->listProjectsForUser($userId);
    $mergedTasks = array_values(array_unique(array_merge($status['agent_projects'], $fromTasks)));
    sort($mergedTasks, SORT_NATURAL | SORT_FLAG_CASE);
    $status['task_projects'] = $mergedTasks;

    $fromKb = $kb->listProjectsForUser($userId);
    $mergedKb = array_values(array_unique(array_merge($status['agent_projects'], $fromKb)));
    sort($mergedKb, SORT_NATURAL | SORT_FLAG_CASE);
    $status['kb_projects'] = $mergedKb;

    $status['project_tool_policies'] = $toolPolicies->listForUser($userId);

    Response::json($status);
});

$notFoundAgent = static function (\RuntimeException $e): bool {
    if ($e->getMessage() === 'not_found') {
        Response::error('Not found', 404);
        return true;
    }
    return false;
};

$forbiddenAgent = static function (\RuntimeException $e, string $message = 'Journal writes require a project on this job'): bool {
    if ($e->getMessage() === 'forbidden') {
        Response::error($message, 403);
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

// Tasks (web) — register /due before /{id}
$router->add('GET', '/tasks/due', function () use ($tasks, $requireUser, $runnerTimezone): void {
    $userId = $requireUser();
    Response::json($tasks->dueForUser($userId, $runnerTimezone()));
});

$router->add('GET', '/tasks', function () use ($tasks, $requireUser): void {
    $userId = $requireUser();
    $projectSpecified = array_key_exists('project', $_GET);
    $project = $projectSpecified ? (string) $_GET['project'] : null;
    $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
    $priority = isset($_GET['priority']) ? (string) $_GET['priority'] : null;
    $q = isset($_GET['q']) ? (string) $_GET['q'] : null;
    $dueFrom = isset($_GET['due_from']) ? (string) $_GET['due_from'] : null;
    $dueTo = isset($_GET['due_to']) ? (string) $_GET['due_to'] : null;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : TaskService::WEB_LIST_DEFAULT;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    try {
        Response::json($tasks->listForUser(
            $userId,
            $project,
            $projectSpecified,
            $status,
            $priority,
            $q,
            $dueFrom,
            $dueTo,
            $limit,
            $offset,
        ));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('GET', '/tasks/{id}', function (array $params) use ($tasks, $requireUser): void {
    $userId = $requireUser();
    $task = $tasks->getForUser($userId, (int) $params['id']);
    if ($task === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($task);
});

$router->add('POST', '/tasks', function () use ($tasks, $requireUser): void {
    $userId = $requireUser();
    try {
        $task = $tasks->createForUser($userId, Request::jsonBody());
        Response::json($task, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('PATCH', '/tasks/{id}', function (array $params) use ($tasks, $requireUser): void {
    $userId = $requireUser();
    try {
        $task = $tasks->updateForUser($userId, (int) $params['id'], Request::jsonBody());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($task === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($task);
});

$router->add('POST', '/tasks/{id}', function (array $params) use ($tasks, $requireUser): void {
    $userId = $requireUser();
    try {
        $task = $tasks->updateForUser($userId, (int) $params['id'], Request::jsonBody());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($task === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($task);
});

$router->add('DELETE', '/tasks/{id}', function (array $params) use ($tasks, $requireUser): void {
    $userId = $requireUser();
    if (!$tasks->deleteForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

$router->add('POST', '/tasks/{id}/delete', function (array $params) use ($tasks, $requireUser): void {
    $userId = $requireUser();
    if (!$tasks->deleteForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

// System prompts (web) — register /defaults before /{id}
$router->add('GET', '/system-prompts', function () use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    Response::json($systemPrompts->bundleForUser($userId));
});

$router->add('POST', '/system-prompts', function () use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    try {
        $row = $systemPrompts->createForUser($userId, Request::jsonBody());
        Response::json($row, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('PUT', '/system-prompts/defaults', function () use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    try {
        Response::json($systemPrompts->setGlobalDefaults($userId, Request::jsonBody()));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('POST', '/system-prompts/defaults', function () use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    try {
        Response::json($systemPrompts->setGlobalDefaults($userId, Request::jsonBody()));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('PUT', '/system-prompts/defaults/projects/{project}', function (array $params) use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    try {
        Response::json($systemPrompts->setProjectDefaults($userId, (string) $params['project'], Request::jsonBody()));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('POST', '/system-prompts/defaults/projects/{project}', function (array $params) use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    try {
        Response::json($systemPrompts->setProjectDefaults($userId, (string) $params['project'], Request::jsonBody()));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('DELETE', '/system-prompts/defaults/projects/{project}', function (array $params) use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    try {
        if (!$systemPrompts->deleteProjectDefaults($userId, (string) $params['project'])) {
            Response::error('Not found', 404);
            return;
        }
        Response::json(['ok' => true, 'project' => (string) $params['project']]);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('POST', '/system-prompts/defaults/projects/{project}/delete', function (array $params) use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    try {
        if (!$systemPrompts->deleteProjectDefaults($userId, (string) $params['project'])) {
            Response::error('Not found', 404);
            return;
        }
        Response::json(['ok' => true, 'project' => (string) $params['project']]);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('GET', '/system-prompts/{id}', function (array $params) use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    $row = $systemPrompts->getForUser($userId, (int) $params['id']);
    if ($row === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($row);
});

$router->add('PATCH', '/system-prompts/{id}', function (array $params) use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    try {
        $row = $systemPrompts->updateForUser($userId, (int) $params['id'], Request::jsonBody());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($row === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($row);
});

$router->add('POST', '/system-prompts/{id}', function (array $params) use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    try {
        $row = $systemPrompts->updateForUser($userId, (int) $params['id'], Request::jsonBody());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($row === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($row);
});

$router->add('DELETE', '/system-prompts/{id}', function (array $params) use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    if (!$systemPrompts->deleteForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

$router->add('POST', '/system-prompts/{id}/delete', function (array $params) use ($systemPrompts, $requireUser): void {
    $userId = $requireUser();
    if (!$systemPrompts->deleteForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

// Project tool policies (web)
$router->add('GET', '/project-tool-policies', function () use ($toolPolicies, $requireUser): void {
    $userId = $requireUser();
    Response::json(['policies' => $toolPolicies->listForUser($userId)]);
});

$router->add('GET', '/project-tool-policies/{project}', function (array $params) use ($toolPolicies, $requireUser): void {
    $userId = $requireUser();
    try {
        $row = $toolPolicies->getForUser($userId, (string) $params['project']);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($row === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($row);
});

$putProjectToolPolicy = function (array $params) use ($toolPolicies, $requireUser): void {
    $userId = $requireUser();
    $body = Request::jsonBody();
    try {
        $row = $toolPolicies->setForUser($userId, (string) $params['project'], $body);
        Response::json($row);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
};

$router->add('PUT', '/project-tool-policies/{project}', $putProjectToolPolicy);
$router->add('POST', '/project-tool-policies/{project}', $putProjectToolPolicy);

$deleteProjectToolPolicy = function (array $params) use ($toolPolicies, $requireUser): void {
    $userId = $requireUser();
    try {
        if (!$toolPolicies->deleteForUser($userId, (string) $params['project'])) {
            Response::error('Not found', 404);
            return;
        }
        Response::json(['ok' => true, 'project' => (string) $params['project']]);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
};

$router->add('DELETE', '/project-tool-policies/{project}', $deleteProjectToolPolicy);
$router->add('POST', '/project-tool-policies/{project}/delete', $deleteProjectToolPolicy);

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
    $ollamaWebSearch = null;
    if (array_key_exists('ollama_web_search', $body)) {
        $ollamaWebSearch = (bool) $body['ollama_web_search'];
    }
    $agentToolIds = null;
    if (isset($body['agent_tool_ids']) && is_array($body['agent_tool_ids'])) {
        $agentToolIds = array_map('strval', $body['agent_tool_ids']);
    }
    $backendState = null;
    if (isset($body['backend_state']) && is_string($body['backend_state'])) {
        $backendState = $body['backend_state'];
    }
    $sdAvailable = null;
    if (array_key_exists('sd_available', $body)) {
        $sdAvailable = (bool) $body['sd_available'];
    }
    $sdModels = null;
    if (isset($body['sd_models']) && is_array($body['sd_models'])) {
        $sdModels = array_map('strval', $body['sd_models']);
    }
    $ollamaModelsStale = null;
    if (array_key_exists('ollama_models_stale', $body)) {
        $ollamaModelsStale = (bool) $body['ollama_models_stale'];
    }
    $runners->heartbeat(
        $runnerId,
        $models,
        $agentProfiles,
        $agentModels,
        $agentProjects,
        $timezone,
        $ollamaWebSearch,
        $agentToolIds,
        $backendState,
        $sdAvailable,
        $sdModels,
        $ollamaModelsStale,
    );
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

$router->add('GET', '/runner/jobs/{id}/tool-policy', function (array $params) use ($toolPolicies, $requireRunner): void {
    $runnerId = $requireRunner();
    try {
        $disabled = $toolPolicies->disabledForRunningJob($runnerId, (int) $params['id']);
        Response::json(['disabled_tools' => $disabled]);
    } catch (\RuntimeException $e) {
        if ($e->getMessage() === 'not_found') {
            Response::error('Not found', 404);
            return;
        }
        throw $e;
    }
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

$router->add('POST', '/runner/jobs/{id}/artifacts', function (array $params) use ($artifacts, $requireRunner): void {
    $runnerId = $requireRunner();
    $file = $_FILES['file'] ?? null;
    if (!is_array($file)) {
        Response::error('Multipart file field "file" is required', 422);
        return;
    }
    try {
        $artifact = $artifacts->uploadForRunner($runnerId, (int) $params['id'], $file);
        Response::json(['artifact' => $artifact], 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        if ($e->getMessage() === 'not_found') {
            Response::error('Job not found or not running on this runner', 404);
            return;
        }
        Response::error($e->getMessage(), 500);
    }
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

// Tasks (runner)
$router->add('GET', '/runner/jobs/{id}/tasks', function (array $params) use ($tasks, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
    $priority = isset($_GET['priority']) ? (string) $_GET['priority'] : null;
    $q = isset($_GET['q']) ? (string) $_GET['q'] : null;
    $dueFrom = isset($_GET['due_from']) ? (string) $_GET['due_from'] : null;
    $dueTo = isset($_GET['due_to']) ? (string) $_GET['due_to'] : null;
    try {
        Response::json($tasks->listForAgent(
            $runnerId,
            (int) $params['id'],
            $status,
            $priority,
            $q,
            $dueFrom,
            $dueTo,
        ));
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
    }
});

$router->add('GET', '/runner/jobs/{id}/tasks/{entryId}', function (array $params) use ($tasks, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $task = $tasks->getForAgent($runnerId, (int) $params['id'], (int) $params['entryId']);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if ($task === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($task);
});

$router->add('POST', '/runner/jobs/{id}/tasks', function (array $params) use ($tasks, $requireRunner, $notFoundAgent, $forbiddenAgent): void {
    $runnerId = $requireRunner();
    try {
        $task = $tasks->createForAgent($runnerId, (int) $params['id'], Request::jsonBody());
        Response::json($task, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        if ($forbiddenAgent($e, 'Task writes require a project on this job') || $notFoundAgent($e)) {
            return;
        }
        throw $e;
    }
});

$router->add('PATCH', '/runner/jobs/{id}/tasks/{entryId}', function (array $params) use ($tasks, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $task = $tasks->updateForAgent(
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
    if ($task === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($task);
});

$router->add('POST', '/runner/jobs/{id}/tasks/{entryId}', function (array $params) use ($tasks, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $task = $tasks->updateForAgent(
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
    if ($task === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($task);
});

$router->add('DELETE', '/runner/jobs/{id}/tasks/{entryId}', function (array $params) use ($tasks, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $ok = $tasks->deleteForAgent($runnerId, (int) $params['id'], (int) $params['entryId']);
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

$router->add('POST', '/runner/jobs/{id}/tasks/{entryId}/delete', function (array $params) use ($tasks, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $ok = $tasks->deleteForAgent($runnerId, (int) $params['id'], (int) $params['entryId']);
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

// Knowledge bases (web)
$router->add('GET', '/kb', function () use ($kb, $requireUser): void {
    $userId = $requireUser();
    $projectSpecified = array_key_exists('project', $_GET);
    $project = $projectSpecified ? (string) $_GET['project'] : null;
    try {
        Response::json(['bases' => $kb->listBasesForUser($userId, $project, $projectSpecified)]);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('POST', '/kb', function () use ($kb, $requireUser): void {
    $userId = $requireUser();
    try {
        $base = $kb->createBaseForUser($userId, Request::jsonBody());
        Response::json($base, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
});

$router->add('GET', '/kb/{id}', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    $base = $kb->getBaseForUser($userId, (int) $params['id']);
    if ($base === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($base);
});

$router->add('PATCH', '/kb/{id}', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    try {
        $base = $kb->updateBaseForUser($userId, (int) $params['id'], Request::jsonBody());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($base === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($base);
});

$router->add('POST', '/kb/{id}', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    try {
        $base = $kb->updateBaseForUser($userId, (int) $params['id'], Request::jsonBody());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($base === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($base);
});

$router->add('DELETE', '/kb/{id}', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    if (!$kb->deleteBaseForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

$router->add('POST', '/kb/{id}/delete', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    if (!$kb->deleteBaseForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

$router->add('POST', '/kb/{id}/rebuild', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    try {
        $result = $kb->rebuildBaseForUser($userId, (int) $params['id']);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($result === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($result);
});

$router->add('GET', '/kb/{id}/documents', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    $docs = $kb->listDocumentsForUser($userId, (int) $params['id']);
    if ($docs === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['documents' => $docs]);
});

$router->add('POST', '/kb/{id}/documents', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    $file = $_FILES['file'] ?? null;
    if (!is_array($file)) {
        // Empty $_FILES usually means the whole body exceeded post_max_size.
        $postMax = (string) ini_get('post_max_size');
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0) {
            Response::error(
                "Upload missing: request was {$contentLength} bytes but PHP post_max_size is {$postMax}. "
                . 'Increase post_max_size / upload_max_filesize for the API.',
                422
            );
            return;
        }
        Response::error('Multipart field "file" is required', 422);
        return;
    }
    try {
        $doc = $kb->uploadDocumentForUser($userId, (int) $params['id'], $file);
        Response::json($doc, 201);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        if ($e->getMessage() === 'not_found') {
            Response::error('Not found', 404);
            return;
        }
        throw $e;
    }
});

$router->add('POST', '/kb/documents/{id}/reingest', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    try {
        $doc = $kb->reingestDocumentForUser($userId, (int) $params['id']);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    }
    if ($doc === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($doc);
});

$router->add('DELETE', '/kb/documents/{id}', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    if (!$kb->deleteDocumentForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

$router->add('POST', '/kb/documents/{id}/delete', function (array $params) use ($kb, $requireUser): void {
    $userId = $requireUser();
    if (!$kb->deleteDocumentForUser($userId, (int) $params['id'])) {
        Response::error('Not found', 404);
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

// Knowledge bases (runner)
$router->add('GET', '/runner/kb/documents/{id}/content', function (array $params) use ($kb, $requireRunner): void {
    $runnerId = $requireRunner();
    try {
        $info = $kb->documentContentForRunner($runnerId, (int) $params['id']);
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'not_found') {
            Response::error('Not found', 404);
            return;
        }
        if (str_starts_with($msg, 'blob_missing')) {
            Response::error($msg, 404);
            return;
        }
        throw $e;
    }
    if ($info === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::file($info['path'], $info['content_type'], $info['filename']);
});

$router->add('POST', '/runner/kb/documents/{id}/status', function (array $params) use ($kb, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $doc = $kb->updateDocumentStatusForRunner($runnerId, (int) $params['id'], Request::jsonBody());
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
        return;
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if ($doc === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($doc);
});

$router->add('POST', '/runner/kb/documents/{id}/purge', function (array $params) use ($kb, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $ok = $kb->finalizeDocumentPurgeForRunner($runnerId, (int) $params['id']);
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
    Response::json(['ok' => true, 'id' => (int) $params['id']]);
});

$router->add('POST', '/runner/kb/{id}/purge', function (array $params) use ($kb, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $ok = $kb->finalizeBasePurgeForRunner($runnerId, (int) $params['id']);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    Response::json(['ok' => true, 'id' => (int) $params['id'], 'deleted' => $ok]);
});

$router->add('GET', '/runner/jobs/{id}/kb', function (array $params) use ($kb, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    $filter = isset($_GET['kb']) ? (string) $_GET['kb'] : null;
    try {
        Response::json($kb->visibleForAgent($runnerId, (int) $params['id'], $filter));
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
    }
});

$router->add('GET', '/runner/jobs/{id}/kb/documents/{docId}', function (array $params) use ($kb, $requireRunner, $notFoundAgent): void {
    $runnerId = $requireRunner();
    try {
        $doc = $kb->getDocumentForAgent($runnerId, (int) $params['id'], (int) $params['docId']);
    } catch (\RuntimeException $e) {
        if (!$notFoundAgent($e)) {
            throw $e;
        }
        return;
    }
    if ($doc === null) {
        Response::error('Not found', 404);
        return;
    }
    Response::json($doc);
});

try {
    $router->dispatch(Request::method(), Request::path());
} catch (\Throwable $e) {
    error_log('OwnRun API error: ' . $e->getMessage());
    Response::error('Internal server error', 500);
}
