<?php
/**
 * @file
 * Backup to GoogleDrive script.
 *
 * Main script file which creates gzip files and sends them to GoogleDrive.
 */

set_time_limit(0);

include_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . "/settings.inc";

define('GOOGLECREDENTIALSPATH', '~/.credentials/google-drive.json');
define('GOOGLECLIENTID', $client_id);
define('GOOGLECLIENTSECRET', $client_secret);
define('GOOGLEREQUESTURI', $request_uri);
define('BACKUPSTMPDIR', $fileroot);
define('WEBROOT', $webroot);
define('SCOPES', implode(' ', array(
  Google_Service_Drive::DRIVE
)));

/**
 * Iterate over $sites.
 */
foreach ($sites as $site) {
  echo ('Starting backup for ' . $site['name'] . '.' . PHP_EOL);

  // Generate the site archive. If database credentials were included generate
  // the database archive as well.
  archive($site, $webroot);

  // Cleanup any archive files leftover in the tmp directory.
  cleanup();

  echo ('Backup complete for ' . $site['name'] . '.' . PHP_EOL);
}

/**
 * Create codebase and database archives.
 *
 * @param array $site
 *   A site configuration array, as in example.site.inc.
 * @param string $webroot
 *   (optional) The webroot. The site docroot will be determined based on the
 *   webroot.
 *
 * @return bool
 *   Status of the operation.
 */
function archive($site, $webroot = '') {
  if (!$site['name']) {
    return FALSE;
  }

  // Use the current date/time as unique identifier.
  $timestamp = date("YmdHis");
  $fileroot = BACKUPSTMPDIR;

  if ($site['docroot']) {
    $site_archive = $fileroot . '/' . $timestamp . '_' . $site['dbname'] . ".tar.gz";
    shell_exec("cd " . $webroot . " && tar cf - " . $site['docroot'] . " -C " . $webroot . " | gzip -9 > " . $site_archive);
    send_archive_to_drive($site_archive, $site['name'] . '/codebase backups');
  }

  if ($site['dbuser'] && $site['dbpass'] && $site['dbname']) {
    $db_archive = $fileroot . '/' . $timestamp . '_' . $site['dbname'] . ".sql.gz";
    shell_exec("mysqldump -u" . $site['dbuser'] . " -p" . $site['dbpass'] . " " . $site['dbname'] . " | gzip -9 > " . $db_archive);
    send_archive_to_drive($db_archive, $site['name'] . '/database backups');
  }
}

/**
 * Send a single file to drive.
 *
 * @param string $file_path
 *   The path to the file to upload.
 * @param string $directory
 *   The directory the file belongs to. The name is used to assign the archive to a
 *   directory.
 * @param bool $cleanup
 *   If true, remove the file after upload.
 */
function send_archive_to_drive($file_path, $directory, $cleanup = TRUE) {
  $client = get_client();
  $result = upload_archive($client, $file_path, $directory);

  if ($result && $cleanup) {
    unlink($file_path);
  }
}

function upload_archive($client, $file_path, $directory) {
  $service = new Google_Service_Drive($client);
  $file = new Google_Service_Drive_DriveFile();
  $file->name = basename($file_path);
  $file->setParents(array(prepare_drive_path($directory)));

  $client->setDefer(TRUE);
  $request = $service->files->create($file);
  $chunk_size = 1 * 1024 * 1024;
  $media = new Google_Http_MediaFileUpload(
    $client,
    $request,
    mime_content_type($file_path),
    NULL,
    TRUE,
    $chunk_size
  );
  $media->setFileSize(filesize($file_path));

  // Upload the various chunks. $status will be false until the process is
  // complete.
  $status = FALSE;
  $handle = fopen($file_path, "rb");
  while (!$status && !feof($handle)) {
    $chunk = fread($handle, $chunk_size);
    $status = $media->nextChunk($chunk);
  }

  // The final value of $status will be the data from the API for the object
  // that has been uploaded.
  $result = FALSE;
  if ($status != FALSE) {
    $result = $status;
  }


  fclose($handle);
  // Reset to the client to execute requests immediately in the future.
  $client->setDefer(FALSE);

  return $result;
}

/**
 * Expands the home directory alias '~' to the full path.
 *
 * @param string $path
 *   The path to expand.
 *
 * @return string
 *   The expanded path.
 */
function expand_home_dir($path) {
  $home_dir = getenv('HOME');
  if (empty($home_dir)) {
    $home_dir = getenv("HOMEDRIVE") . getenv("HOMEPATH");
  }
  return str_replace('~', realpath($home_dir), $path);
}

/**
 * Get an authenticated google Client.
 *
 * @return \Google_Client
 *   The google client.
 */
function get_client() {
  $client = new Google_Client();

  // Get your credentials from the APIs Console.
  $client->setClientId(GOOGLECLIENTID);
  $client->setClientSecret(GOOGLECLIENTSECRET);
  $client->setRedirectUri(GOOGLEREQUESTURI);
  $client->setAccessType("offline");
  $client->setScopes(SCOPES);

  $credentials = expand_home_dir(GOOGLECREDENTIALSPATH);
  if (!file_exists($credentials)) {
    // Exchange authorization code for access token.
    $auth_url = $client->createAuthUrl();
    printf("Open the following link in your browser:\n%s\n", $auth_url);
    shell_exec("
      if command -v open >/dev/null
      then
        open '" . $auth_url . "'
      else
        xdg-open '" . $auth_url . "'
      fi"
    );
    print 'Enter verification code: ';
    $auth_code = trim(fgets(STDIN));
    $access_token = $client->authenticate($auth_code);

    // Save token for future use.
    if (!file_exists(dirname($credentials))) {
      mkdir(dirname($credentials));
    }
    file_put_contents($credentials, json_encode($access_token));
    printf("Credentials saved to %s\n", $credentials);
  }
  else {
    $access_token = file_get_contents($credentials);
  }
  $client->setAccessToken($access_token);

  if ($client->isAccessTokenExpired()) {
    $refresh_token = $client->getRefreshToken();
    $client->refreshToken($refresh_token);
    $new_access_token = $client->getAccessToken();
    $new_access_token['refresh_token'] = $refresh_token;
    file_put_contents($credentials, json_encode($new_access_token));
  }

  return $client;
}

/**
 * Parse path components and establish parent folder hierarchy for Google Drive.
 *
 * @param \Google_Service_Drive $service
 *   The google drive service.
 * @param string $path
 *   The path to prepare.
 *
 * @return string
 *   The id of the last folder component in the path.
 */
function prepare_drive_path($path) {
  $folders = explode('/', $path);
  $id = NULL;
  for ($i = 0; $i < count($folders); $i++) {
    $parent = $i > 0 ? $folders[$i - 1] : NULL;
    $id = prepare_folder($folders[$i], $parent);
  }

  return $id;
}

/**
 * Return the id of a folder in google drive.
 *
 * The method will create the folder if it does not exist already. If a parent
 * directory is provided, the method will attempt to create the parent first.
 *
 * @param \Google_Service_Drive $service
 *   The google drive service.
 * @param string $folder
 *   The name of the folder.
 * @param string|NULL $parent
 *   The name if the parent folder.
 *
 * @return string
 *   The file id of the google drive folder.
 */
function prepare_folder($folder, $parent = NULL) {
  $client = get_client();
  $service = new Google_Service_Drive($client);
  $parent_id = $parent ? prepare_folder($parent) : FALSE;
  $params = array(
    'q' => "
      mimeType = 'application/vnd.google-apps.folder' and
      name = '" . $folder . "' and
      trashed = false
    ",
  );

  if ($parent_id) {
    $params['q'] = $params['q'] . " and '" . $parent_id . "' in parents";
  }

  $directories = $service->files->listFiles($params)->getFiles();
  if (!empty($directories)) {
    return $directories[0]->getId();
  }
  else {
    // Create the folder and return the id.
    $file = new Google_Service_Drive_DriveFile();
    $file->setName($folder);
    $file->setDescription("Reese Creative Backup directory.");
    $file->setMimeType("application/vnd.google-apps.folder");
    if ($parent_id) {
      $file->setParents(array($parent_id));
    }
    return $service->files->create($file, array('fields' => "id"))->id;
  }
}

/**
 * Send orphaned archives to google drive.
 *
 * This is not run by default, but could be used to backup stray files to google
 * drive before cleanup().
 */
function send_orphaned_archives_to_drive() {
  $client = get_client();
  $service = new Google_Service_Drive($client);

  if ($files = find_archives()) {
    foreach ($files as $file_path) {
      upload_archive($service, $file_path, 'unsorted');
      unlink($file_path);
    }
  };
}

/**
 * Attempt to clean up any leftover archive files.
 */
function cleanup() {
  $stray_archives = find_archives();
  foreach ($stray_archives as $stray_archive) {
    unlink($stray_archive);
  }
}

/**
 * Find archives in the BACKUPSTMPDIR directory.
 *
 * @return array
 *   The array of files matching the globbing pattern "*.gz".
 */
function find_archives() {
  return glob(BACKUPSTMPDIR . '/*.gz');
}
