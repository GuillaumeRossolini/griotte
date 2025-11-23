<?php

function html(string $output_tpl) {
  $output_html = vsprintf($output_tpl, array_slice(func_get_args(), 1));
  return htmlspecialchars($output_html, ENT_QUOTES);
}

function http($http_status, $http_body=null) {
  http_response_code($http_status);
  if($http_body) {
    echo $http_body;
  }
}

function trace($priority, $errmsg_tpl) {
  $errmsg = vsprintf($errmsg_tpl, array_slice(func_get_args(), 2));
  syslog($priority, $errmsg);
  error_log($errmsg);
}
