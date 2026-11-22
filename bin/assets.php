<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$target = "$root/public/assets/vendor";
if (!is_dir($target)) {
    mkdir($target, 0755, true);
}
foreach (['dist/css/bootstrap.min.css', 'LICENSE'] as $file) {
    if (!copy("$root/vendor/twbs/bootstrap/$file", "$target/" . basename($file))) {
        exit(1);
    }
}
