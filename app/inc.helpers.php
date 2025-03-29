<?php

function html(string $output_tpl) {
  $output_args = func_get_args();
  array_shift($output_args);
  $output_html = vsprintf($output_tpl, $output_args ?: []);
  return htmlspecialchars($output_html, ENT_QUOTES);
}
