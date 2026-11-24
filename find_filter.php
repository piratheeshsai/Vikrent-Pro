<?php
$dir = 'wp-content/plugins/vikrentcar';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $c = file_get_contents($file->getPathname());
        if (strpos($c, 'get_supported_payments_vikrentcar') !== false) {
            echo "Found filter in: " . $file->getPathname() . PHP_EOL;
        }
    }
}
