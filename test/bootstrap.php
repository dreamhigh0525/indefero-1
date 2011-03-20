<?php

define('SRCDIR', realpath(dirname(__FILE__) . '/../src'));
define('TESTDIR', dirname(__FILE__));
define('DATADIR', TESTDIR . '/data');

set_include_path(get_include_path() . PATH_SEPARATOR . SRCDIR);
