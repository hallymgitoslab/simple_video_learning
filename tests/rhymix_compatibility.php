<?php

declare(strict_types=1);

// Run against a real Rhymix 2.1.3 checkout (no configured database required).
// SVL_RHYMIX_ROOT=/path/to/rhymix php tests/rhymix_compatibility.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$completed = false;
register_shutdown_function(static function () use (&$completed): void {
    if (!$completed) {
        fwrite(STDERR, "Rhymix compatibility checks did not complete.\n");
        exit(1);
    }
});
$root = getenv('SVL_RHYMIX_ROOT');
if (!$root || !is_file($root . '/common/autoload.php')) {
    throw new RuntimeException('SVL_RHYMIX_ROOT must point to a Rhymix checkout.');
}
require_once $root . '/common/autoload.php';
Context::getInstance();

use Rhymix\Modules\Simple_video_learning\Src\Controllers\ApiController;
use Rhymix\Modules\Simple_video_learning\Src\Controllers\MoodleCompatController;
use Rhymix\Modules\Simple_video_learning\Src\Models\CourseApiTokenModel;
use Rhymix\Modules\Simple_video_learning\Src\Models\ConfigModel;

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function invokePrivate(object $object, string $method, array $args = [])
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

check(function_exists('str_starts_with'), 'Rhymix PHP compatibility helper is missing');
check(CourseApiTokenModel::authenticate('invalid-prefix') === null, 'Invalid course token was accepted');
echo "PASS actual Rhymix bootstrap supplies PHP 7.4 string compatibility\n";

$api = (new ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
$compat = (new ReflectionClass(MoodleCompatController::class))->newInstanceWithoutConstructor();
$plain = 'integration-test-secret';
$hash = hash('sha256', $plain);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $plain;
check(invokePrivate($api, 'authorize', [$hash]) === null, 'Native global token authentication changed');
check(invokePrivate($compat, 'authenticateToken', [$hash, $plain]) === null, 'Moodle global token authentication changed');
check(invokePrivate($compat, 'authenticateToken', [$hash, $hash]) === false, 'Stored digest can be used as the token');
check(invokePrivate($compat, 'authenticateToken', [$hash, '']) === false, 'Empty global token was accepted');
echo "PASS native and Moodle APIs accept the original secret but reject its stored digest\n";

$GLOBALS['__ModuleConfig__']['simple_video_learning'] = (object)['api_token' => $plain];
foreach ([new ConfigModel(), new ConfigModel()] as $configModel) {
    check($configModel->needsTokenMigration(), 'Another model instance skipped the pending token migration');
    check($configModel->get()->api_token_hash === $hash, 'Legacy token digest changed');
}
check($GLOBALS['__ModuleConfig__']['simple_video_learning']->api_token === $plain, 'Unsaved migration mutated the actual Rhymix config cache');
unset($GLOBALS['__ModuleConfig__']['simple_video_learning']);
echo "PASS token migration preserves the actual Rhymix config cache until saved\n";

Context::set('logged_info', (object)['is_admin' => 'Y']);
$malicious = '<p>allowed</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">link</a>';
foreach ([$api, $compat] as $controller) {
    $html = invokePrivate($controller, 'buildContent', [10, $malicious, 'https://example.invalid/video.mp4', 60, 80]);
    check(strpos($html, 'allowed') !== false, 'Safe HTML was removed');
    check(!preg_match('/<script|onerror\s*=|javascript\s*:/i', $html), 'API content retained executable HTML');
}
echo "PASS API HTML is sanitized by the real Rhymix filter even with an administrator session\n";

$dir = invokePrivate($compat, 'draftDirectory');
check(strpos(realpath($dir) . DIRECTORY_SEPARATOR, realpath(RX_BASEDIR) . DIRECTORY_SEPARATOR) !== 0, 'Drafts remain inside the web root');
check((fileperms($dir) & 0077) === 0, 'Draft directory is not private');
$draftId = random_int(100000, 999999);
$file = $dir . '/' . $draftId . '_fixture.mp4';
$metaPath = $dir . '/' . $draftId . '.json';
$outside = tempnam(sys_get_temp_dir(), 'svl_outside_');
try {
    file_put_contents($file, 'fixture');
    $meta = ['path' => $file, 'token_hash' => $hash, 'created_at' => time()];
    file_put_contents($metaPath, json_encode($meta));
    check(invokePrivate($compat, 'loadDraft', [$draftId, $plain])['path'] === $file, 'Valid private draft was rejected');
    $denied = false;
    try { invokePrivate($compat, 'loadDraft', [$draftId, 'other-secret']); }
    catch (RuntimeException $e) { $denied = true; }
    check($denied, 'Draft token ownership was not checked');
    $meta['created_at'] = time() - 86401;
    file_put_contents($metaPath, json_encode($meta));
    $denied = false;
    try { invokePrivate($compat, 'loadDraft', [$draftId, $plain]); }
    catch (RuntimeException $e) { $denied = true; }
    check($denied, 'Expired draft was accepted');
    $meta['created_at'] = time();
    $meta['path'] = $outside;
    file_put_contents($metaPath, json_encode($meta));
    $denied = false;
    try { invokePrivate($compat, 'loadDraft', [$draftId, $plain]); }
    catch (RuntimeException $e) { $denied = true; }
    check($denied, 'Draft metadata escaped the private directory');
    echo "PASS private draft storage checks ownership, expiry, and path boundaries\n";
} finally {
    @unlink($file);
    @unlink($metaPath);
    @unlink($outside);
    @rmdir($dir);
}
$completed = true;
echo "RHYMIX_COMPATIBILITY_COMPLETE\n";
