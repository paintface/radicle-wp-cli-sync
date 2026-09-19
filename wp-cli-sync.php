<?php
/*
Plugin Name:  WP-CLI Sync
Description:  A WP-CLI command for syncing a live site to a development environment
Version:      1.3.1
Author:       Jon Beaumont-Pike
Author URI:   https://jonbp.co.uk/
License:      MIT License
*/

// Set Default Vars
$env_variables = array(
	'LIVE_SSH_HOSTNAME',
	'LIVE_SSH_USERNAME',
	'REMOTE_PROJECT_LOCATION',
	'DEV_ACTIVATED_PLUGINS',
	'DEV_DEACTIVATED_PLUGINS',
	'DEV_POST_SYNC_QUERIES',
	'DEV_SYNC_DIR_EXCLUDES',
	'DEV_TASK_DEBUG',
	'UPLOAD_DIR'
);

// getenv() returns false (not null) when unset, so ?? alone never falls through
foreach ($env_variables as $env_variable) {
	$value = $_ENV[$env_variable] ?? getenv($env_variable);
	$_ENV[$env_variable] = ($value === false || $value === null || $value === '') ? getDefault($env_variable) : $value;
}

function getDefault($env_variable): string
{
	return $env_variable === 'UPLOAD_DIR' ? 'web/app/uploads' : '';
}

// Define Sync Command
if ( defined( 'WP_CLI' ) && WP_CLI ) {
  $sync = function($args, $assoc_args) {

    // Flags: --database / --media limit the sync; neither = sync everything.
    // WP-CLI passes --no-<flag> as false, so isset() would treat it as opted-in.
    // filter_var: bare flag / --flag=true → true, --no-flag / --flag=false → false
    $database_flag  = isset($assoc_args['database']) ? filter_var($assoc_args['database'], FILTER_VALIDATE_BOOLEAN) : null;
    $media_flag     = isset($assoc_args['media']) ? filter_var($assoc_args['media'], FILTER_VALIDATE_BOOLEAN) : null;
    $only_requested = ($database_flag === true) || ($media_flag === true);
    $sync_database  = $database_flag ?? !$only_requested;
    $sync_media     = $media_flag ?? !$only_requested;

    if (!$sync_database && !$sync_media) {
      WP_CLI::error('Nothing to sync: both --no-database and --no-media given.');
    }

    // Message helpers (each guarded: redeclaring fatals if sync runs twice in one process)
    if (!function_exists('task_message')) {
      function task_message($message, $title='Task', $color = 34, $firstBreak = true) {
        if($firstBreak == true) {
          echo "\n";
        }
        echo "\033[".$color."m".$title.": ".$message."\n\033[0m";
      }
    }

    if (!function_exists('debug_message')) {
      function debug_message($message, $title='Debug', $color = 33, $firstBreak = false) {
        if (empty($_ENV['DEV_TASK_DEBUG'])) {
          return;
        }
        if ($firstBreak == true) {
          echo "\n";
        }
        echo "\033[".$color."m".$title.": ".$message."\n\033[0m";
      }
    }

    // Line Break + Color Reset
    if (!function_exists('lb_cr')) {
      function lb_cr() {
        echo "\n\033[0m";
      }
    }

    // Fail Count Var
    $fail_count = 0;

    // Sync vars
    $ssh_hostname = $_ENV['LIVE_SSH_HOSTNAME'];
    $ssh_username = $_ENV['LIVE_SSH_USERNAME'];
    $rem_proj_loc = $_ENV['REMOTE_PROJECT_LOCATION'];
    $upload_dir = $_ENV['UPLOAD_DIR'];

    // Welcome
    task_message('Running .env file and connection checks...', 'WP-CLI Sync', 97);

    /**
     * BEGIN VAR / CONNECTION CHECKS
     */

    // Exit if some vars missing
    if (empty($ssh_hostname) || empty($ssh_username) || empty($rem_proj_loc)) {

      // Exit Messages
      task_message('some/all dev sync vars are not set in .env file', 'Error', 31, false);

      // Line Break + Color Reset + Exit
      lb_cr();
      exit(1);

    }

    // Check if Remote location formatted correctly
    if(($rem_proj_loc[0] != '/') && ($rem_proj_loc[0] != '~')) {

      // Exit Messages
      task_message('Incorrect formatting of the REMOTE_PROJECT_LOCATION variable', 'Error', 31, false);
      task_message('Ensure that the path begins with either / or ~/', 'Hint', 33);

      // Line Break + Color Reset + Exit
      lb_cr();
      exit(1);

    } elseif($rem_proj_loc[0] == '~') {

      if($rem_proj_loc[1] != '/') {

        // Exit Messages
        task_message('Incorrect formatting of the REMOTE_PROJECT_LOCATION variable', 'Error', 31, false);
        task_message('Ensure that the path begins with either / or ~/', 'Hint', 33);

        // Line Break + Color Reset + Exit
        lb_cr();
        exit(1);

      }

    }

    // Check if SSH connection works
    $command = 'ssh -q '.$ssh_username.'@'.$ssh_hostname.' exit; echo $?';
    $live_server_status = exec($command);

    if ($live_server_status == '255') {

      // Exit Messages
      task_message('Cannot connect to live server over SSH', 'Error', 31, false);
      task_message('Check that your LIVE_SSH_HOSTNAME and LIVE_SSH_USERNAME variables are correct', 'Hint', 33);

      // Line Break + Color Reset + Exit
      lb_cr();
      exit(1);

    }

    // Check if WP-CLI is installed on live server (only needed for database sync)
    if ($sync_database) {

      $command = 'ssh -q '.$ssh_username.'@'.$ssh_hostname.' "bash -c \"test -f '.$rem_proj_loc.'/vendor/bin/wp && echo true || echo false\""';
      $live_server_check = exec($command);

      // Anything but an explicit 'true' (e.g. empty output from a dropped ssh) is a failure
      if ($live_server_check !== 'true') {

        // Exit Messages
        task_message('Connected but cannot find remote WP-CLI', 'Error', 31, false);
        task_message('Either WP-CLI Sync is not installed on the live server or the REMOTE_PROJECT_LOCATION variable is incorrect', 'Hint', 33);

        // Line Break + Color Reset + Exit
        lb_cr();
        exit(1);

      }

    }

    // Checks Success
    task_message('Running sync...', 'Connected', 32, false);

    // Plugin Vars
    $dev_activated_plugins = $_ENV['DEV_ACTIVATED_PLUGINS'];
    $dev_deactivated_plugins = $_ENV['DEV_DEACTIVATED_PLUGINS'];

    // Move to project root
    chdir(ABSPATH.'../../');

    /**
     * TASK: Database Sync
     */
    if ($sync_database) {

      // Activate Maintenance Mode (media-only syncs don't touch the database)
      $command = ABSPATH . '/../../vendor/bin/wp maintenance-mode activate';
      exec($command);

      $task_name = 'Sync Database';
      task_message($task_name);

      // pv check
      if (shell_exec('which pv')) {
        $pipe = '| pv |';
      } else {
        task_message('Install the \'pv\' command to monitor import progress', 'Notice', 33, false);
        $pipe = '|';
      }

      $command = 'ssh '.$ssh_username.'@'.$ssh_hostname.' "bash -c \"cd '.$rem_proj_loc.' && '.$rem_proj_loc.'/vendor/bin/wp db export --single-transaction -\"" '.$pipe. ' ' . ABSPATH . '/../../vendor/bin/wp db import -';
      debug_message($command);
      // pipefail so a failed remote export can't silently import a truncated dump
      system('bash -c '.escapeshellarg('set -o pipefail; '.$command), $db_status);
      if ($db_status !== 0) {
        task_message('Database sync failed (exit code '.$db_status.')', 'Error', 31);
        $fail_count++;
      }

      /**
       * TASK: Post sync queries (skipped if the import failed)
       */
      if ($db_status === 0 && ($queries = $_ENV['DEV_POST_SYNC_QUERIES'])) {
        $command = ABSPATH . '/../../vendor/bin/wp db query "' . preg_replace('/(`|")/i', '\\\\${1}', $queries) . '"';
        debug_message($command);
        system($command);
      }

    }


    /**
     * TASK: Sync Uploads Folder
     */
    if ($sync_media) {

      $task_name = 'Sync Uploads Folder';

      $excludes  = '';
      if ($exclude_dirs = $_ENV['DEV_SYNC_DIR_EXCLUDES']) {
        $exclude_dirs = explode(',', $exclude_dirs);
        foreach ($exclude_dirs as $dir) {
          $excludes .= ' --exclude=' . escapeshellarg($dir);
        }
      }

      if (shell_exec('which rsync')) {
        task_message($task_name);
        $command = 'rsync -avhP --timeout=120 -e "ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=6" '.escapeshellarg($ssh_username.'@'.$ssh_hostname.':'.$rem_proj_loc.'/'.$upload_dir.'/').' '.escapeshellarg('./'.$upload_dir.'/') . $excludes;
        debug_message($command);
        system($command, $rsync_status);
        // 24 = files vanished mid-transfer, routine when the live site is writing uploads
        if ($rsync_status !== 0 && $rsync_status !== 24) {
          task_message('Uploads sync failed (rsync exit code '.$rsync_status.')', 'Error', 31);
          $fail_count++;
        }
      } else {
        task_message($task_name.' task not ran, please install \'rsync\'', 'Error', 31);
        $fail_count++;
      }

    }

    /**
     * TASK: Activate / Deactivate Plugins
     */
    if ($sync_database) {

      // Activate Plugins
      if (!empty($dev_activated_plugins)) {
        task_message('Activate Plugins');
        $cleaned_arr_list = preg_replace('/[ ,]+/', ' ', trim($dev_activated_plugins));
        $command = ABSPATH . '/../../vendor/bin/wp plugin activate '.$cleaned_arr_list;
        debug_message($command);
        system($command);
      }

      // Deactivate Plugins
      if (!empty($dev_deactivated_plugins)) {
        task_message('Deactivate Plugins');
        $cleaned_arr_list = preg_replace('/[ ,]+/', ' ', trim($dev_deactivated_plugins));
        $command = ABSPATH . '/../../vendor/bin/wp plugin deactivate '.$cleaned_arr_list;
        debug_message($command);
        system($command);
      }

      // Deactivate Maintenance Mode
      $command = ABSPATH . '/../../vendor/bin/wp maintenance-mode deactivate';
      exec($command);

    }

    // Completion Message
    if ($fail_count > 0) {
      task_message('Finished with '.$fail_count. ' errors', 'Warning', 33);
      lb_cr();
      exit(1);
    }
    task_message('All Tasks Finished', 'Success', 32);

    // Final Line Break + Color Reset
    lb_cr();

  };

  WP_CLI::add_command('sync', $sync, array(
    'synopsis' => array(
      array('type' => 'flag', 'name' => 'database', 'optional' => true, 'description' => 'Only sync the database'),
      array('type' => 'flag', 'name' => 'media', 'optional' => true, 'description' => 'Only sync the uploads folder'),
    ),
  ));
}