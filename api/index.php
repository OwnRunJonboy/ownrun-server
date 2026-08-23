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
use OwnRun\Config;
use OwnRun\Database;
use OwnRun\JobService;
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
$router = new Router();

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
    $model = trim((string) ($body['model'] ?? ''));
    $prompt = trim((string) ($body['prompt'] ?? ''));
    $systemPrompt = isset($body['system_prompt']) ? trim((string) $body['system_prompt']) : null;

    if ($model === '' || $prompt === '') {
        Response::error('Model and prompt are required', 422);
        return;
    }

    try {
        $job = $jobs->create($userId, $model, $prompt, $systemPrompt);
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
$router->add('GET', '/models', function () use ($runners, $requireUser): void {
    $requireUser();
    Response::json($runners->status());
});

// Runner endpoints
$router->add('POST', '/runner/heartbeat', function () use ($runners, $requireRunner): void {
    $runnerId = $requireRunner();
    $body = Request::jsonBody();
    $models = null;
    if (isset($body['available_models']) && is_array($body['available_models'])) {
        $models = array_map('strval', $body['available_models']);
    }
    $runners->heartbeat($runnerId, $models);
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
    $ok = $jobs->heartbeat($runnerId, (int) $params['id']);
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
    $ok = $jobs->complete($runnerId, (int) $params['id'], $result);
    if (!$ok) {
        Response::error('Job not found or not running', 404);
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
        Response::error('Job not found or not running', 404);
        return;
    }
    Response::json(['ok' => true]);
});

try {
    $router->dispatch(Request::method(), Request::path());
} catch (\Throwable $e) {
    error_log('OwnRun API error: ' . $e->getMessage());
    Response::error('Internal server error', 500);
}
