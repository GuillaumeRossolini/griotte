<?php

function html(string $output_tpl) {
  $output_html = vsprintf($output_tpl, array_slice(func_get_args(), 1));
  return htmlspecialchars($output_html, ENT_QUOTES);
}

function http($http_status, $http_body) {
  http_response_code($http_status);
  echo $http_body;
}

function trace($priority, $errmsg_tpl) {
  $errmsg = vsprintf($errmsg_tpl, array_slice(func_get_args(), 2));
  syslog($priority, $errmsg);
  error_log($errmsg);
}
