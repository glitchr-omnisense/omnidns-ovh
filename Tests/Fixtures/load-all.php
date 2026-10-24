<?php

/*
 * Loads every class of the package (but its tests and its harness) with
 * Composer's autoloader, then prints what was loaded: BareTest's child
 * process. Arguments: the autoloader, the package's directory, and classes
 * to load besides (the check that it is not blind).
 */
[, $autoload, $root] = $argv;
require $autoload;
$root = realpath($root);
$loaded = [];
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static fn (SplFileInfo $file) => !in_array($file->getFilename(), ['Tests', 'docker', 'vendor', 'docs', '.git'], true),
));
foreach ($iterator as $file) {
    // Not the former names of another package's classes (class_alias() stubs), nor a class over a
    // suggested package (marked "@suggested <package>"): they load that package.
    $source = $file instanceof SplFileInfo && 'php' === $file->getExtension() ? (string) file_get_contents($file->getPathname()) : '';
    if ('' === $source || str_contains($source, 'class_alias(') || str_contains($source, '@suggested ')) {
        continue;
    }
    $class = 'Omnidns\\Ovh\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
    if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
        $loaded[] = $class;
    }
}
foreach (array_slice($argv, 3) as $class) {
    class_exists($class);
}
echo json_encode([
    'loaded' => $loaded,
    'symbols' => [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
    'files' => array_map('realpath', get_included_files()),
    'eager' => array_map('realpath', array_values(is_file($f = dirname($autoload).'/composer/autoload_files.php') ? require $f : [])),
], JSON_UNESCAPED_SLASHES);
