<?php
header('Content-Type: text/plain; charset=UTF-8');

echo "PHP: " . PHP_VERSION . "\n\n";

$exts = ['zip','xml','gd','mbstring','fileinfo'];
foreach ($exts as $e) {
    echo $e . ": " . (extension_loaded($e) ? "ON" : "OFF") . "\n";
}

echo "\nZipArchive class: " . (class_exists('ZipArchive') ? "YES" : "NO") . "\n";
