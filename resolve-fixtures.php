#!/usr/bin/env php
<?php

// resolve-fixtures.php

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/vendor/autoload.php';

$scanDir = __DIR__ . '/core/tests/Functional';
$namespacePrefix = 'ApiPlatform\\Tests\\Fixtures\\';

$fixturesBaseDir = __DIR__ . '/core/tests/Fixtures'; 

if (!is_dir($scanDir)) {
    fwrite(STDERR, "Directory not found: $scanDir\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanDir));
$regex = '/^use\s+(' . preg_quote($namespacePrefix) . '[^;\s]+)/m';
$foundClasses = [];

echo "Scanning for classes in $scanDir...\n";

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    
    if (preg_match_all($regex, $content, $matches)) {
        foreach ($matches[1] as $class) {
            $parts = explode(' as ', $class);
            $className = trim($parts[0]);
            
            $foundClasses[] = $className;
        }
    }
}

$uniqueClasses = array_unique($foundClasses);
sort($uniqueClasses);

$foundCount = 0;
$missingCount = 0;

foreach ($uniqueClasses as $class) {
    $filePath = null;

    if ($loaderPath = $loader->findFile($class)) {
        $filePath = $loaderPath;
    } 
    else {
        $relativeClass = str_replace($namespacePrefix, '', $class);
        $manualPath = $fixturesBaseDir . '/' . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($manualPath)) {
            $filePath = realpath($manualPath);
        }
    }

    if ($filePath) {
        echo $filePath . PHP_EOL;
        $foundCount++;
    } else {
        fwrite(STDERR, "Warning: Could not resolve class $class\n");
        $missingCount++;
    }
}

fwrite(STDERR, "\nResult: $foundCount found, $missingCount missing.\n");
