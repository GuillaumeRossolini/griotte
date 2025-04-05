<?php

set_include_path(__DIR__.'/../app');
require_once 'inc.constants.php';
require_once 'inc.helpers.php';

register_shutdown_function(function() {
  syslog(LOG_DEBUG, sprintf('Script finished after %0.3fms', microtime(true)-GRIOTTE_STARTTIME));
});


if(!empty($_POST)) {
  require 'api.php';
  exit;
}

if(!empty($_GET['script'])) {
  if(!file_exists($_GET['script'])) {
    http_response_code(404);
    die('ko');
  }

  header('Content-Type: application/javascript; charset=utf-8', true);
  header('Cache-Control: public, immutable', true);
  readfile($_GET['script']);
  exit;
}


require 'graphs.php';
