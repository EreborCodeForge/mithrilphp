<?php

declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Container;
use App\Core\Environment;
use App\Core\Exceptions\ExceptionHandler;
use App\Core\Http\Request;
use App\Core\Logger\FileLogger;
use App\Core\Logger\LoggerInterface;
use App\Core\Router;
use App\Domain\Repositories\ProductRepositoryInterface;
use App\Domain\Repositories\UserRepositoryInterface;
use App\Domain\Services\StorageServiceInterface;
use App\Infrastructure\Repositories\PDOProductRepository;
use App\Infrastructure\Repositories\PDOUserRepository;
use App\Infrastructure\Services\LocalStorageService;
use App\Presentation\Controllers\AuthController;
use App\Presentation\Controllers\UserController;
use App\Presentation\Middleware\AuthMiddleware;

Environment::load(__DIR__ . '/../.env');

// Initialize Logger
$logger = new FileLogger(__DIR__ . '/../logs/app.log');

// Setup Exception Handler
$exceptionHandler = new ExceptionHandler($logger, getenv('APP_DEBUG') === 'true');
set_exception_handler([$exceptionHandler, 'handle']);

try {
    // Setup Container
    $container = new Container();

    // Bind Services
    $container->bind(LoggerInterface::class, FileLogger::class);
    $container->bind(StorageServiceInterface::class, function() {
        $driver = Environment::get('FILESYSTEM_DRIVER', 'local');
        $appUrl = Environment::get('APP_URL', 'http://localhost');
        $appPort = Environment::get('APP_PORT');
        
        $baseUrl = $appUrl;
        if ($appPort && !str_contains($appUrl, ":$appPort")) {
            $baseUrl .= ':' . $appPort;
        }
        
        return match ($driver) {
            'local' => new LocalStorageService(__DIR__ . '/../public/storage', $baseUrl),
            default => throw new Exception("Unsupported filesystem driver: $driver"),
        };
    });

    // Bind Repositories
    $container->bind(ProductRepositoryInterface::class, PDOProductRepository::class);
    $container->bind(UserRepositoryInterface::class, PDOUserRepository::class);

    // Bind Use Cases
    $container->bind(App\Application\UseCases\Auth\LoginUseCaseInterface::class, App\Application\UseCases\Auth\LoginUseCase::class);
    $container->bind(App\Application\UseCases\Auth\RegisterUseCaseInterface::class, App\Application\UseCases\Auth\RegisterUseCase::class);

    // Initialize Router with Container
    $router = new Router($container);

    // Web Routes
    $router->get('/', [App\Presentation\Controllers\HomeController::class, 'index']);
    $router->get('/login', [App\Presentation\Controllers\LoginController::class, 'show']);
    
    // Resource Routes (for serving raw assets from resources directory)
    $router->get(
    '/resources/{path:.*}',
        [App\Presentation\Controllers\ResourceController::class, 'serve']
    );

    // Auth Routes
    $router->post('/api/login', [AuthController::class, 'login']);
    $router->post('/api/register', [AuthController::class, 'register']);

    // Health Check
    $router->get('/api/health', [App\Presentation\Controllers\HealthCheckController::class, 'check']);

    // User Routes
    $router->get('/api/users', [UserController::class, 'index'], [AuthMiddleware::class]);

    // Dispatch
    $request = Request::createFromGlobals();
    $response = $router->dispatch($request);
    $response->send();

} catch (Throwable $e) {
    // Last resort error handling
    if (getenv('APP_DEBUG') === 'true') {
        echo "<h1>Fatal Error</h1>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    } else {
        http_response_code(500);
        echo "Internal Server Error";
    }
    // Log it
    $logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
}
