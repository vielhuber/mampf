<?php
declare(strict_types=1);

use Mampf\Runtime;

$root = dirname(path: __DIR__, levels: 2);
require $root . '/vendor/autoload.php';

$runtime = new Runtime(root: $root);
$path = basename(path: (string) parse_url(url: $_SERVER['REQUEST_URI'] ?? '', component: PHP_URL_PATH));
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if ($requestMethod === 'POST' && $path === 'cookie') {
    $input = json_decode(json: (string) file_get_contents(filename: 'php://input'), associative: true);
    $token = is_array(value: $input) ? (string) ($input['access_token'] ?? '') : '';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    if (!$runtime->auth->isLoggedIn()) {
        http_response_code(response_code: 401);
        header(header: 'Content-Type: application/json');
        echo json_encode(value: ['success' => false]);
        exit();
    }
    setcookie('access_token', $token, [
        'expires' => time() + 60 * 60 * 24 * 365,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    header(header: 'Content-Type: application/json');
    echo json_encode(value: ['success' => true]);
    exit();
}
if ($requestMethod === 'POST' && $path === 'logout') {
    setcookie('access_token', '', [
        'expires' => 1,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    header(header: 'Content-Type: application/json');
    echo json_encode(value: ['success' => true]);
    exit();
}
$runtime->auth->init();
