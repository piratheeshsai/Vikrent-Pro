<?php
$dir = 'wp-content/plugins/vikrentcar';
$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $c = file_get_contents($f->getPathname());
        if (stripos($c, 'permission') !== false || stripos($c, 'acl') !== false) {
            if (strpos($f->getPathname(), 'admin') !== false || strpos($f->getPathname(), 'view') !== false) {
                $found[] = $f->getPathname();
            }
        }
    }
}
echo "Found in " . count($found) . " files.\n";
foreach (array_slice($found, 0, 15) as $path) {
    echo $path . "\n";
}
