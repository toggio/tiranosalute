<?php

// Punto d'ingresso unico dell'applicazione.
// Da qui passano bootstrap, route API, SPA, spec OpenAPI
// e gestione del base path dinamico.

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AppointmentController;
use App\Controllers\AuthController;
use App\Controllers\AvailabilityController;
use App\Controllers\CategoryController;
use App\Controllers\MetaController;
use App\Controllers\ReportController;
use App\Core\BasePath;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\AppointmentRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\PatientRepository;
use App\Repositories\ReportRepository;
use App\Repositories\StatsRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use App\Repositories\WebSessionRepository;
use App\Services\AppointmentService;
use App\Services\AuthService;
use App\Services\AvailabilityService;
use App\Services\ReportService;
use App\Services\StatsService;
use App\Services\UserManagementService;

$config = require __DIR__ . '/bootstrap.php';
$pdo = Database::getConnection($config);
$request = Request::capture();
$rawRequestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$detectedBasePath = BasePath::detect();

if (
    $request->getMethod() === 'GET'
    && $detectedBasePath !== ''
    && $rawRequestPath === $detectedBasePath
) {
    $target = $detectedBasePath . '/';
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($query !== '') {
        $target .= '?' . $query;
    }

    header('Location: ' . $target, true, 302);
    exit;
}

applyCors($config);
if ($request->getMethod() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = $request->getPath();
if ($path === '/openapi.json') {
    sendNoIndexHeaders();
    serveOpenApi($config);
}

if ($path === '/swagger' || $path === '/swagger/' || $path === '/swagger/index.html') {
    serveHtmlTemplate(__DIR__ . '/swagger/index.html', true);
}

if (str_starts_with($path, '/swagger/')) {
    $swaggerFile = realpath(__DIR__ . $path);
    if (
        $swaggerFile === false
        || !is_file($swaggerFile)
        || !str_starts_with($swaggerFile, realpath(__DIR__))
    ) {
        Response::error('NOT_FOUND', 'Risorsa non trovata', 404);
    }
}

if ($path === '/favicon.ico') {
    $favicon = __DIR__ . '/assets/icons/favicon.ico';
    if (!is_file($favicon)) {
        Response::error('NOT_FOUND', 'Risorsa non trovata', 404);
    }

    header('Content-Type: image/x-icon');
    readfile($favicon);
    exit;
}

$publicFile = realpath(__DIR__ . $path);
if (
    $publicFile !== false
    && is_file($publicFile)
    && str_starts_with($publicFile, realpath(__DIR__))
    && isAllowedPublicStaticPath($path, $publicFile)
) {
    $ext = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };
    if ($path === '/robots.txt') {
        sendNoIndexHeaders();
    }
    header('Content-Type: ' . $mime);
    readfile($publicFile);
    exit;
}

$userRepo = new UserRepository($pdo);
$patientRepo = new PatientRepository($pdo);
$doctorRepo = new DoctorRepository($pdo);
$availabilityRepo = new AvailabilityRepository($pdo);
$appointmentRepo = new AppointmentRepository($pdo);
$reportRepo = new ReportRepository($pdo);
$tokenRepo = new TokenRepository($pdo);
$webSessionRepo = new WebSessionRepository($pdo);
$categoryRepo = new CategoryRepository($pdo);
$statsRepo = new StatsRepository($pdo);

$authService = new AuthService($userRepo, $tokenRepo, $webSessionRepo, $config);
$reportService = new ReportService($reportRepo, $appointmentRepo, $userRepo, $config);
$appointmentService = new AppointmentService($pdo, $appointmentRepo, $availabilityRepo, $doctorRepo, $patientRepo, $categoryRepo, $reportService, $config);
$userService = new UserManagementService($pdo, $userRepo, $patientRepo, $doctorRepo);
$availabilityService = new AvailabilityService($availabilityRepo, $doctorRepo);
$statsService = new StatsService($statsRepo, $appointmentService);

$authController = new AuthController($authService, $userService, $config);
$categoryController = new CategoryController($categoryRepo);
$appointmentController = new AppointmentController($appointmentService);
$reportController = new ReportController($reportService);
$availabilityController = new AvailabilityController($availabilityService);
$adminController = new AdminController($userService, $appointmentService, $statsService);
$metaController = new MetaController($config);

if (!str_starts_with($path, '/api/')) {
    if ($path === '/api') {
        Response::ok(['service' => 'Tirano Salute API']);
    }

    if (isBlockedInternalPath($path)) {
        Response::error('NOT_FOUND', 'Risorsa non trovata', 404);
    }

    if (isSpaEntryPath($path)) {
        serveHtmlTemplate(__DIR__ . '/index.html', true);
    }

    Response::error('NOT_FOUND', 'Risorsa non trovata', 404);
}

$router = new Router();

$withAuth = function (callable $handler, array $roles = [], bool $checkCsrf = true) use ($authService, $config): callable {
    return function (Request $request) use ($handler, $roles, $checkCsrf, $authService, $config): mixed {
        $auth = $authService->authenticate(
            (string)$request->getHeader('authorization', ''),
            (string)$request->getCookie((string)($config['auth_cookie_name'] ?? 'ts_auth'), '')
        );
        $auth = $authService->requireAuth($auth);

        if ($roles) {
            $authService->requireRole($auth, $roles);
        }

        if (!empty($auth->user['must_change_password']) && !isPasswordChangeAllowedPath($request->getPath())) {
            throw new \App\Core\HttpException(
                'Devi aggiornare la password temporanea prima di continuare.',
                403,
                'PASSWORD_CHANGE_REQUIRED'
            );
        }

        // Dual auth: cookie HttpOnly + CSRF double submit per operazioni write, bearer token senza CSRF.
        $isWrite = in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ($checkCsrf && $isWrite && $auth->method === 'cookie') {
            $authService->ensureCsrfHeaderValid(
                $auth,
                (string)$request->getHeader('x-csrf-token', ''),
                (string)$request->getCookie((string)($config['csrf_cookie_name'] ?? 'ts_csrf'), '')
            );
        }

        return $handler($request, $auth);
    };
};

$router->add('GET', '/api/meta', fn() => $metaController->appInfo());
$router->add('POST', '/api/login', fn(Request $req) => $authController->login($req));
$router->add('POST', '/api/login/bearer', fn(Request $req) => $authController->tokenLogin($req));
$router->add('POST', '/api/logout', $withAuth(fn(Request $req, $auth) => $authController->logout($auth, $req), [], true));
$router->add('GET', '/api/me', $withAuth(fn(Request $req, $auth) => $authController->me($auth), [], false));
$router->add('POST', '/api/change-password', $withAuth(fn(Request $req, $auth) => $authController->changePassword($auth, $req), [], true));

$router->add('GET', '/api/categories', $withAuth(fn() => $categoryController->list(), [], false));
$router->add('GET', '/api/doctors', $withAuth(
    function (Request $req, $auth) use ($appointmentController, $adminController): array {
        $scope = trim((string)$req->getQueryParam('scope', ''));
        if ($scope === 'all') {
            if (!in_array($auth->role(), ['RECEPTION', 'INTEGRATOR'], true)) {
                throw new \App\Core\HttpException('Permessi insufficienti', 403, 'FORBIDDEN');
            }
            return $adminController->listDoctors();
        }

        return $appointmentController->listDoctors();
    },
    [],
    false
));
$router->add('GET', '/api/availability/search', $withAuth(fn(Request $req, $auth) => $appointmentController->searchAvailability($auth, $req), [], false));

$router->add('POST', '/api/appointments/book', $withAuth(
    fn(Request $req, $auth) => $appointmentController->book($auth, $req),
    ['PATIENT', 'RECEPTION', 'INTEGRATOR'],
    true
));
$router->add('GET', '/api/appointments', $withAuth(fn(Request $req, $auth) => $appointmentController->list($auth, $req), [], false));
$router->add('GET', '/api/appointments/{id}', $withAuth(fn(Request $req, $auth) => $appointmentController->detail($auth, $req), [], false));
$router->add('POST', '/api/appointments/{id}/cancel', $withAuth(
    fn(Request $req, $auth) => $appointmentController->cancel($auth, $req),
    ['PATIENT', 'DOCTOR', 'RECEPTION', 'INTEGRATOR'],
    true
));
$router->add('POST', '/api/appointments/{id}/start', $withAuth(
    fn(Request $req, $auth) => $appointmentController->start($auth, $req),
    ['DOCTOR'],
    true
));
$router->add('POST', '/api/appointments/{id}/complete', $withAuth(
    fn(Request $req, $auth) => $appointmentController->complete($auth, $req),
    ['DOCTOR'],
    true
));

$router->add('GET', '/api/reports', $withAuth(
    fn(Request $req, $auth) => $reportController->list($auth, $req),
    ['PATIENT', 'DOCTOR', 'INTEGRATOR'],
    false
));
$router->add('GET', '/api/reports/{id}', $withAuth(
    fn(Request $req, $auth) => $reportController->detail($auth, $req),
    ['PATIENT', 'DOCTOR', 'INTEGRATOR'],
    false
));
$router->add('GET', '/api/reports/{id}/download', $withAuth(
    fn(Request $req, $auth) => $reportController->download($auth, $req),
    ['PATIENT', 'DOCTOR', 'INTEGRATOR'],
    false
));

$router->add('GET', '/api/doctors/{id}/availability', $withAuth(
    fn(Request $req, $auth) => $availabilityController->get($auth, $req),
    ['DOCTOR', 'RECEPTION', 'INTEGRATOR'],
    false
));
$router->add('PUT', '/api/doctors/{id}/availability', $withAuth(
    fn(Request $req, $auth) => $availabilityController->set($auth, $req),
    ['DOCTOR', 'RECEPTION', 'INTEGRATOR'],
    true
));

$router->add('GET', '/api/patients', $withAuth(fn(Request $req) => $adminController->listPatients($req), ['RECEPTION', 'INTEGRATOR'], false));
$router->add('POST', '/api/patients', $withAuth(fn(Request $req) => $adminController->createPatient($req), ['RECEPTION', 'INTEGRATOR'], true));
$router->add('PUT', '/api/patients/{id}', $withAuth(fn(Request $req) => $adminController->updatePatient($req), ['RECEPTION', 'INTEGRATOR'], true));
$router->add('GET', '/api/staff', $withAuth(fn() => $adminController->listStaff(), ['INTEGRATOR'], false));
$router->add('POST', '/api/staff', $withAuth(fn(Request $req) => $adminController->createStaff($req), ['INTEGRATOR'], true));
$router->add('PUT', '/api/staff/{id}', $withAuth(fn(Request $req) => $adminController->updateStaff($req), ['INTEGRATOR'], true));

$router->add('POST', '/api/doctors', $withAuth(fn(Request $req) => $adminController->createDoctor($req), ['RECEPTION', 'INTEGRATOR'], true));
$router->add('PUT', '/api/doctors/{id}', $withAuth(fn(Request $req) => $adminController->updateDoctor($req), ['RECEPTION', 'INTEGRATOR'], true));

$router->add('GET', '/api/stats', $withAuth(fn() => $adminController->statsDashboard(), ['INTEGRATOR'], false));

$router->dispatch($request);

function applyCors(array $config): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $allowed = $config['cors']['allow_origins'] ?? ['*'];
        if (in_array('*', $allowed, true) || in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        }
    }

    header('Access-Control-Allow-Methods: ' . implode(', ', $config['cors']['allow_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']));
    header('Access-Control-Allow-Headers: ' . implode(', ', $config['cors']['allow_headers'] ?? ['Content-Type', 'Authorization', 'X-CSRF-Token']));
}

function serveOpenApi(array $config): never
{
    $basePath = BasePath::detect();
    $templatePath = __DIR__ . '/docs/openapi.template.json';
    if (!is_file($templatePath)) {
        Response::error('NOT_FOUND', 'Spec OpenAPI non trovata', 404);
    }

    $raw = file_get_contents($templatePath);
    if ($raw === false) {
        Response::error('SERVER_ERROR', 'Impossibile leggere la spec OpenAPI', 500);
    }

    $json = str_replace(
        ['__BASE_PATH__', '__AUTH_COOKIE_NAME__'],
        [$basePath, (string)($config['auth_cookie_name'] ?? 'ts_auth')],
        $raw
    );
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        Response::error('SERVER_ERROR', 'Spec OpenAPI non valida', 500, json_last_error_msg());
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function serveHtmlTemplate(string $file, bool $sendNoIndex = false): never
{
    if (!is_file($file)) {
        Response::error('NOT_FOUND', 'Pagina non trovata', 404);
    }

    $html = file_get_contents($file);
    if ($html === false) {
        Response::error('SERVER_ERROR', 'Impossibile leggere la pagina richiesta', 500);
    }

    if ($sendNoIndex) {
        sendNoIndexHeaders();
    }

    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('__BASE_PATH__', BasePath::detect(), $html);
    exit;
}

function isAllowedPublicStaticPath(string $requestedPath, string $resolvedPath): bool
{
    if ($requestedPath === '/robots.txt') {
        $robotsFile = realpath(__DIR__ . '/robots.txt');
        return $robotsFile !== false && $resolvedPath === $robotsFile;
    }

    foreach (['/assets', '/swagger'] as $publicDir) {
        $root = realpath(__DIR__ . $publicDir);
        if (
            $root !== false
            && str_starts_with($resolvedPath, $root . DIRECTORY_SEPARATOR)
        ) {
            return true;
        }
    }

    return false;
}

function isSpaEntryPath(string $path): bool
{
    return in_array($path, ['/', '/index.php', '/index.html'], true);
}

function sendNoIndexHeaders(): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function isBlockedInternalPath(string $path): bool
{
    foreach (['/data/', '/src/', '/docs/', '/scripts/'] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

function isPasswordChangeAllowedPath(string $path): bool
{
    return in_array($path, [
        '/api/me',
        '/api/change-password',
        '/api/logout',
    ], true);
}
