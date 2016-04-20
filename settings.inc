<?php
/**
 * @file
 * Backup to GoogleDrive example script.
 *
 * Copyright (C) 2016 Reese Creative <http://www.reesecreativestudio.com>
 *
 * settings.inc
 * Settings required for script execution.
 */

ini_set('memory_limit', '1024M'); // Suggested by Sam http://goo.gl/tUw4wY

// The servers webroot,
$webroot = "";

$fileroot = __DIR__ . "/tmp";

// Google Drive Client ID.
$client_id = "";

// Google Drive Client Secret.
$client_secret = "";

// Request URI (Suggested by Sam http://goo.gl/tUw4wY ).
$request_uri = "urn:ietf:wg:oauth:2.0:oob";

// Populate site settings using a globbing pattern.
$sites = array();

// Add site configuration files.
foreach (glob("sites/*.site.inc") as $filename) {
  require_once $filename;
}

// @todo: Search webroot for site settings files / drupal settings.php
