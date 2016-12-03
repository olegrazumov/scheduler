<?php

try {
    $sourcePath = __DIR__;
    $loaderPath = realpath(__DIR__ . '/../vendor/autoload.php');
    $loader = require_once $loaderPath;
    $loader->addPsr4('Web\\', $sourcePath);
    \Web\Ui::handleRequest();
} catch (Throwable $e) {
    echo 'Unhandled error(' .$e->getCode() . '): ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
} catch (Exception $e) {
    echo 'Unhandled error(' .$e->getCode() . '): ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
}
