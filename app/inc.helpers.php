<?php

/**
 * Ends the script
 */
function finish(int $response_code, string $reponse_body='ok'): void {
  // trace(LOG_DEBUG, 'Response sent after %0.3fms', microtime(true)-GRIOTTE_STARTTIME);
  http($response_code, $reponse_body);
  exit;
}

/**
 * Prepares output for HTML content
 */
function html(string $output_tpl): string {
  $output_html = vsprintf($output_tpl, array_slice(func_get_args(), 1));
  return htmlspecialchars($output_html, ENT_QUOTES);
}

/**
 * Send an HTTP response code and echo the content
 */
function http($http_status, $http_body=null): void {
  http_response_code($http_status);
  if($http_body) {
    echo $http_body;
  }
}

/**
 * Essentially a safer touch() function for php-fpm
 * (which apparently can't create files on the fly)
 */
function truncate(string $filename, $do_trunc, ?int $filemtime): bool {
  if(!file_exists($filename || $do_trunc)) {
    $_handle = fopen($filename, 'w');
    if(!$_handle) {
      trace(LOG_ERR, 'Unable to truncate file: %s', $filename);
      return false;
    }
    fclose($_handle);
  }

  if(!touch($filename, $filemtime)) {
    trace(LOG_ERR, 'Unable to set timestamp to file: %s', $filename);
    return false;
  }

  return true;
}

/**
 * Log a trace with parameters
 */
function trace($priority, string $errmsg_tpl): void {
  $errmsg = vsprintf($errmsg_tpl, array_slice(func_get_args(), 2));
  syslog($priority, $errmsg);
  error_log($errmsg);
}
