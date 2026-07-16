<?php
declare(strict_types=1);

use Mampf\Application;
use Mampf\Runtime;

$root = dirname(path: __DIR__);
require $root . '/vendor/autoload.php';

$runtime = new Runtime(root: $root);
new Application(runtime: $runtime)->run();
