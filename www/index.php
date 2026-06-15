<?php

set_include_path(__DIR__.'/../app');
require_once 'inc.constants.php';
require_once 'inc.helpers.php';

register_shutdown_function(function() {
  trace(LOG_DEBUG, 'Script finished after %0.3fs', (microtime(true)-GRIOTTE_STARTTIME));
});


if(!empty($_POST)) {
  require 'main.incoming.php';
  exit;
}


header('X-Frame-Options: SAMEORIGIN', true);
header('X-Content-Type-Options: nosniff', true);
header('X-XSS-Protection: 1; mode=block', true);

if(isset($_GET['phpi'])) {
  phpinfo();
  exit;
}

require 'main.graphs.php';
