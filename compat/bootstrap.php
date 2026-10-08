<?php

declare(strict_types=1);

/**
 * Bootstrap the minimum Rhymix runtime needed by the Moodle-compatible
 * endpoints without re-entering index.php / ModuleHandler routing.
 */
function simple_video_learning_compat_run(string $act): void
{
    $dir = __DIR__;
    $root = null;

    for ($i = 0; $i < 8; $i++) {
        if (is_file($dir . '/common/autoload.php') && is_file($dir . '/index.php')) {
            $root = $dir;
            break;
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    if ($root === null) {
        simple_video_learning_compat_bootstrap_error('Rhymix root not found');
    }

    chdir($root);
    require_once $root . '/common/autoload.php';

    try {
        // Initialize Rhymix request/session/database state, but deliberately do
        // NOT invoke ModuleHandler. The physical compatibility endpoint URL is
        // not a Rhymix route and would otherwise be converted into a 404.
        \Context::init();

        $class = '\\Rhymix\\Modules\\Simple_video_learning\\Src\\Controllers\\MoodleCompatController';
        if (!class_exists($class)) {
            throw new \RuntimeException('Simple Video Learning compatibility controller could not be loaded.');
        }

        /** @var \Rhymix\Modules\Simple_video_learning\Src\Controllers\MoodleCompatController $controller */
        $controller = $class::getInstance('simple_video_learning');
        $controller->setAct($act);

        if (!method_exists($controller, $act)) {
            throw new \RuntimeException('Unsupported compatibility action: ' . $act);
        }

        $controller->{$act}();

        // Compatibility controller methods normally terminate after emitting
        // JSON. Keep a safe fallback in case a future method returns instead.
        \Context::close();
    } catch (\Throwable $e) {
        error_log('[simple_video_learning] Compatibility bootstrap error: ' . $e->getMessage());
        try {
            \Context::close();
        } catch (\Throwable $ignored) {
        }
        simple_video_learning_compat_bootstrap_error('Simple Video Learning compatibility endpoint failed.');
    }
}

function simple_video_learning_compat_bootstrap_error(string $message): void
{
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'exception' => 'moodle_exception',
        'errorcode' => 'bootstrap',
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
