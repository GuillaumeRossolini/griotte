<?php

if(!empty($_POST)) {
  require '../app/api.php';
  exit;
}

if(!empty($_GET['script'])) {
  if(!file_exists($_GET['script'])) {
    http_response_code(400);
    die('ko');
  }

  header('Content-Type: application/javascript; charset=utf-8', true);
  header('Cache-Control: public, immutable');
  readfile($_GET['script']);
  exit;
}


require '../app/graphs.php';
