#!/usr/bin/env php
<?php

// resolve-fixtures.php

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/vendor/autoload.php';

$scanDir = __DIR__ . '/core/tests/Functional';
$namespacePrefix = 'ApiPlatform\\Tests\\Fixtures\\';

if (!is_dir($scanDir)) {
    fwrite(STDERR, "Directory not found: $scanDir\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanDir));
$regex = '/^use\s+(' . preg_quote($namespacePrefix) . '[^;\s]+)/m';
$foundClasses = [];

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    
    if (preg_match_all($regex, $content, $matches)) {
        foreach ($matches[1] as $class) {
            // Handle "use Class as Alias"
            $parts = explode(' as ', $class);
            $foundClasses[] = trim($parts[0]);
        }
    }
}

$uniqueClasses = array_unique($foundClasses);
sort($uniqueClasses);

foreach ($uniqueClasses as $class) {
    if ($filePath = $loader->findFile($class)) {
        echo realpath($filePath) . PHP_EOL;
    } else {
        fwrite(STDERR, "Warning: Could not resolve class $class\n");
    }
}