<?php

define('GRIOTTE_STARTTIME', microtime(true)); // timestamp for later reference
define('GRIOTTE_FOLDER', realpath(__DIR__.'/..')); // system path of the project
define('GRIOTTE_RUN', '/var/run/griotte'); // system path of the run files
define('GRIOTTE_DELAY', 60*1); // delay in seconds before new readings are accepted for a node
