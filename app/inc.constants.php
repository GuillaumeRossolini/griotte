<?php

/**
 * Timestamp for later reference
 */
define('GRIOTTE_STARTTIME', microtime(true));

/**
 * System path of the project
 */
define('GRIOTTE_FOLDER', getenv('GRIOTTE_FOLDER') ?: realpath(__DIR__.'/..'));

/**
 * System path of the databse files
 */
define('GRIOTTE_DB', getenv('GRIOTTE_DB') ?: realpath(GRIOTTE_FOLDER.'/db'));

/**
 * System path of the run-files
 * These are empty files whose presence and modified time are the attributes we need
 */
define('GRIOTTE_RUN', getenv('GRIOTTE_RUN') ?: realpath(GRIOTTE_FOLDER.'/run'));

/**
 * Delay in seconds before new readings are accepted for a node
 * This delay is computed from a node's run-file modified timestamp
 */
define('GRIOTTE_NODE_DELAY', (int) (getenv('GRIOTTE_NODE_DELAY') ?: 1*60));

/**
 * Delay in seconds before new readings are committed to a database
 * This delay is computed from the global buffer-file modified timestamp
 */
define('GRIOTTE_BUFFER_DELAY', (int) (getenv('GRIOTTE_BUFFER_DELAY') ?: 5*60));

/**
 * Display name for the web app
 */
define('GRIOTTE_LABEL', getenv('GRIOTTE_LABEL') ?: 'Griotte');

/**
 * Allowlist of struct types for the input payload
 */
define('GRIOTTE_STRUCT_ALLOWLIST', ['bme680']);


define('GRIOTTE_DAILY_DB_TPL', '%s/daily/%d/%s/%s.sq3');
