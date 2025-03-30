<?php

/**
 * Timestamp for later reference
 */
define('GRIOTTE_STARTTIME', microtime(true));

/**
 * System path of the project
 */
define('GRIOTTE_FOLDER', realpath(__DIR__.'/..'));

/**
 * System path of the run-files
 * These are empty files whose presence and modified time are the attributes we need
 */
define('GRIOTTE_RUN', realpath(GRIOTTE_FOLDER.'/run'));

/**
 * Delay in seconds before new readings are accepted for a node
 * This delay is computed from a node's run-file modified timestamp
 */
define('GRIOTTE_DELAY', 60*1);
