<?php

$config_file =  dirname(__FILE__) . '/config.php';
if (!file_exists($config_file)) {
    die("'test/config.php' does not exist\n");
}

// prevents function calls in Pluf that break tests under php-cli
define('IN_UNIT_TESTS', 1);

echo ">>> setting paths...\n";
define('SRCDIR', realpath(dirname(__FILE__) . '/../src'));
define('TESTDIR', dirname(__FILE__));
define('DATADIR', TESTDIR . '/data');
set_include_path(get_include_path() . PATH_SEPARATOR . SRCDIR);

$testconfig = require_once $config_file;
if (file_exists($testconfig['db_database'])) {
    echo ">>> removing any existing database\n";
    unlink($testconfig['db_database']);
}

echo ">>> creating empty test database...\n";
passthru('php ' . escapeshellarg(PLUF_PATH.'/migrate.php') . ' --conf=' . escapeshellarg(TESTDIR.'/config.php').' -a -i');

echo ">>> setting up web application...\n";
require 'Pluf.php';
// for PHPUnit 3.5 and beyond this is needed, since it comes with its own class loader
spl_autoload_register('__autoload');
Pluf::start(TESTDIR . '/config.php');
