<?php
set_time_limit(900); // 15 minutes
error_reporting(E_ALL);
/**
 * Backing up and restore with only ftp-access
 */

/**
 * Settings
 */
$params = array();

$params['backup_files'] = false;
$params['backup_db'] = false;
$params['filter_ip'] = '127.0.0.1'; // false / string. '0.0.0.0,1.1.1.1' for example

$params['backup_folder'] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/_custom_backup';
$params['backup_files_name'] = 'backup_' . date("Y-m-d_H-i-s") . '_files';
$params['pack_dir'] = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$params['exclude_folders'] = array(
    '/webp',
    '/avif',
); // relative from document_root, e.g. '/images'

$params['backup_db_name'] = 'backup_' . date("Y-m-d_H-i-s") . '_db';
$params['db_host'] = 'localhost';
$params['db_user'] = 'abcde_user';
$params['db_password'] = 'abcde_pass';
$params['db_name'] = 'abcde_database_name';
$params['gzip_db'] = true;
$params['restore_db'] = true; // true / false. Show button for restoring

$params['mail_to'] = 'yourmail@domain.com';
$params['mail_subject'] = $_SERVER['HTTP_HOST'] . ' backup';
$params['mail_message'] = '';
$params['mail_headers'] = 'MIME-Version: 1.0' . "\r\n";
$params['mail_headers'] .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
$params['mail_headers'] .= 'To: me <'.$params['mail_to'].'>' . "\r\n";
$params['mail_headers'] .= 'From: backuper <admin@'.$_SERVER['HTTP_HOST'].'>' . "\r\n";

/**
 * Let's Rock-N-Roll!
 */

// logger
function writeLog($logdata, $logfile = false){
    if (!$logfile){
        $logfile = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/backuper_log.txt';
    }
    date_default_timezone_set( 'Europe/Moscow' );
    $date = date('d/m/Y H:i:s', time());
    file_put_contents($logfile, $date.': '.$logdata.PHP_EOL, FILE_APPEND | LOCK_EX);
}

// check by ip
function is_allowed($filter_ip){
    if (!$filter_ip){
        return true; // filtering is off
    } else {
        $allowed_ip_set = explode(',', $filter_ip);
        foreach ($allowed_ip_set as $index => $ip){
            if ($_SERVER['REMOTE_ADDR'] == trim($ip)){
                return true;
            }
        }

        return false; // list walked, but no true-returns
    }
}

// create backup folder
function create_path($path){

    $path = rtrim($path, '/');

    writeLog('create_path(): $path = '.$path);

    if (!file_exists($path)){
        $result = mkdir($path, 0777 , true);
        informer('create_path(): creating '.$path.': '.($result ? 'successfully': 'error'));
    } else {
        $result = true;
    }

    if ($result){
        informer('create_path(): returning '.$path);
        return $path; // return trimmed path if success
    } else {
        informer('create_path(): returning false');
        return false;
    }
}

// files
function backupFiles($backup_folder, $backup_name, $exclude, $pack_dir) {

    informer('backupFiles(): $backup_folder = '.$backup_folder . '; $backup_name = ' . $backup_name . '; $exclude = ' . print_r($exclude, true). '; $pack_dir = ' . $pack_dir);

    // check existing and trim
    $backup_folder = create_path($backup_folder);

    $fullFileName = $backup_folder . '/' . $backup_name . '.tar.gz';

    $exclude_list = '';
    if (!empty($exclude)){
        foreach ($exclude as $item){
            $command = $pack_dir . '/' . trim($item, '/');
            $command = "--exclude='" . $command . "'";

            // add to stack
            if ($exclude_list){
                $exclude_list .= ' ';
            }

            $exclude_list .= $command;
        }
    }

    $command = "tar --exclude='".$backup_folder."' " . ($exclude_list ? $exclude_list . ' ' : '') . "-cvf " . $fullFileName . " " . $pack_dir;
    informer('backupFiles(): command: ' . $command);
    $result = shell_exec($command);

    //writeLog('backupFiles(): result is {'.PHP_EOL.$result.PHP_EOL.'}');

    return $fullFileName;
}

// db
function backupDB($backup_folder, $backup_name, $db_params = false, $gzip = false) {

    informer('backupDB(): $backup_folder = '.$backup_folder . '; $backup_name = ' . $backup_name . '; $db_params = ' . print_r($db_params, true));

    if (!$db_params || !isset($db_params['host']) || !isset($db_params['user']) || !isset($db_params['password']) || !isset($db_params['name'])){
        return false;
    }

    // check existing and trim
    $backup_folder = create_path($backup_folder);

    $fullFileName = $backup_folder . '/' . $backup_name . '.sql';

    if ($gzip){
        $command = 'mysqldump -h ' . $db_params['host'] . ' -u ' . $db_params['user'] . ' -p' . $db_params['password'] . ' ' . $db_params['name'] . ' | gzip > ' . $fullFileName . '.gz';
    } else {
        $command = 'mysqldump -h ' . $db_params['host'] . ' -u ' . $db_params['user'] . ' -p' . $db_params['password'] . ' ' . $db_params['name'] . ' > ' . $fullFileName;
    }

    $result = shell_exec($command);
    informer('backupDB(): result is "'.$result.'"');
    return $fullFileName;
}

function restore_db($backup_folder, $backup_name, $db_params = false, $gzip = false){
    $message = 'restore_db(): $restore_folder = '.$backup_folder . '; $restore_name = ' . $backup_name . '; $db_params = ' . print_r($db_params, true);
    informer($message);

    if (!$db_params || !isset($db_params['host']) || !isset($db_params['user']) || !isset($db_params['password']) || !isset($db_params['name'])){
        return false;
    }

    // compile bkp name
    $bkp = $backup_folder . '/' . $backup_name;
    informer('restore_db(): $bkp is "'.$bkp);

    // check if exists
    if (!file_exists($bkp)){
        $message = 'restore_db(): '.$bkp." isn't exist!";
        informer($message);
        return false;
    }

    // check if gzipped
    if ($gzip || (mb_strpos($backup_name, '.sql.gz') !== false)){
        // gunzip < /path/to/outputfile.sql.gz | mysql --user=USER -pPASSWORD DATABASE
        $command = 'gunzip < ' . $bkp . ' | mysql --user=' . $db_params['user'] . ' -p' . $db_params['password'] . ' ' . $db_params['name'];
    } else {
        //mysql --user=root -pPASSWORD DATABASE < backup_2021-06-02_11-36-11.sql
        $command = 'mysql --user=' . $db_params['user'] . ' -p' . $db_params['password'] . ' ' . $db_params['name'] . ' < ' . $bkp;
    }

    $message = 'restore_db(): $command is "'.$command.'"';
    informer($message);

    $result = shell_exec($command);

    $message = 'restore_db(): result is "'.$result.'"';
    informer($message);
    return $result;
}

// get sizes
function showSizes($pack_dir){
    $command = "du -sh $pack_dir/*";
    informer('showSizes(): executing "'.$command.'"');
    
    $result = shell_exec($command);
    writeLog('showSizes(): result is:'.PHP_EOL.$result);
    
    return $result;
}

// informer - store to log and print
function informer($message){
    if (!$message){
        return false;
    }

    // logging
    writeLog($message);

    // echo
    echo '<p class="informer">'.preg_replace("/^([a-zA-Z\w\(\)\:\-]+)/", '<strong>$1</strong>', $message) . '</p>';
    flush(); // send to browser

    return true;
}

// convert bytes
function formatSize($bytes) {

    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    }

    elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    }

    elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    }

    elseif ($bytes > 1) {
        $bytes = $bytes . ' байты';
    }

    elseif ($bytes == 1) {
        $bytes = $bytes . ' байт';
    }

    else {
        $bytes = '0 байтов';
    }

    return $bytes;
}

// 
function backup_action(&$params){
    $start = microtime(true); // timer start

    try {

        // files
        if ($params['backup_files']){
            $filesBackup_result = backupFiles($params['backup_folder'], $params['backup_files_name'], $params['exclude_folders'], $params['pack_dir']);

            informer('$filesBackup_result = '.($filesBackup_result ? $filesBackup_result : 'false'));
        }

        // database
        if ($params['backup_db']){
            $dbBackup_result = backupDB($params['backup_folder'], $params['backup_db_name'], array(
                'host' => $params['db_host'],
                'user' => $params['db_user'],
                'password' => $params['db_password'],
                'name' => $params['db_name']
            ), $params['gzip_db']);

            informer('$dbBackup_result = '.($dbBackup_result ? $dbBackup_result : 'false'));
        }

    } catch (\Exception $e) {
        if (isset($e)){
            informer($e->getMessage());
        }
    }

    // reporting to mail
    if (isset($filesBackup_result) && $filesBackup_result){
        $files_message = 'Files backed successfully!<br/>';
        $files_message .= 'Files: ' . $filesBackup_result . '<br/>-------------<br/>';

        informer($files_message);
        $params['mail_message'] .= $files_message;
    }

    if (isset($dbBackup_result) && $dbBackup_result){
        $db_message = 'Database backed successfully!<br/>';
        $db_message .= 'DB: ' . $dbBackup_result . '<br/>-------------<br/>';

        informer($db_message);
        $params['mail_message'] .= $db_message;
    }

    $time = microtime(true) - $start; // calculate time to execution
    $message = 'backup_action(): Script time: ' . $time;

    informer($message);

    $params['mail_message'] .= '<br/>' . $message . '<br/>'; // and store stat

    $mail_result = mail($params['mail_to'], $params['mail_subject'], $params['mail_message'], $params['mail_headers']);

    informer('$mail_result = '.($mail_result ? 'true' : 'false'));

    echo '<br /><a style="display: inline-block; margin: 0 auto; text-decoration: none; padding: 5px 10px; border: 1px solid lightgray; background: rgb(245,245,245);" href="'.strtok($_SERVER['REQUEST_URI'], '?').'">Go back</a>';
}

//
function restore_action(&$params){
    // db restoring. Get bases list, then if $_GET['restoredb'] == 'true' - just show list, else try to restore base with passed name
    if ($_GET['restoredb'] == 'true'){
        writeLog('restore_action(): строим список бэкапов баз');

        // build and show list

        $backup_folder = $params['backup_folder'];

        // searching
        $finded_bkps = glob("$backup_folder/*.sql*");

        if (!empty($finded_bkps)){
            foreach ($finded_bkps as $bkp_file) {
                $bkp_filename = array_reverse(explode('/', $bkp_file))[0];
                echo '<p><a class="btn bkp-link db" href="'.strtok($_SERVER['REQUEST_URI'], '?').'?restoredb='.$bkp_filename.'">'.$bkp_filename.' ('.formatSize(filesize($bkp_file)).')</a></p>';
            }
        } else {
            informer("restore_action(): db backups doesn't exists!");
        }
        
    } else if ($_GET['restoredb']){
        // possibly, passed name. Try to restore
        $result = restore_db($params['backup_folder'], $_GET['restoredb'], array(
            'host' => $params['db_host'],
            'user' => $params['db_user'],
            'password' => $params['db_password'],
            'name' => $params['db_name']
        ));
        informer($result);
    }
    
    echo '<p><a class="btn" href="'.strtok($_SERVER['REQUEST_URI'], '?').'">Go back</a></p>';
}

// start action
function start_action(&$params){
    echo '<pre>' . showSizes($params['pack_dir']) . '</pre>';
    echo '<p><a class="btn" href="'.strtok($_SERVER['REQUEST_URI'], '?').'?go=true">Start backup</a></p>';
    if ($params['restore_db']){
        echo '<p><a class="btn" href="'.strtok($_SERVER['REQUEST_URI'], '?').'?restoredb=true">Restore DB</a></p>';
    }
}

// main
if (!is_allowed($params['filter_ip'])){
    informer("Main: isn't allowed! Exiting...");
    die();
} else {
    echo '<style>';
    echo "
    body{font-family: monospace}
    .informer{color: #8c8c8c;white-space: pre-wrap;word-break: break-all;font-size: 14px}
    strong{color: #000}
    .btn{
        display: inline-block; margin: 0 auto; text-decoration: none; padding: 5px 10px; border: 1px solid lightgray; background: rgb(245,245,245);
    }
    .bkp-link{}
    .bkp-link::before{
        content: '';
        display: inline-block;
        background: transparent center / contain no-repeat;
        width: 16px;
        height: 16px;
        line-height: 16px;
        margin-right: 5px;
        vertical-align: middle;
    }
    .bkp-link.db[href$='.sql']::before{
        background-image: url('data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiID8+DQo8IURPQ1RZUEUgc3ZnIFBVQkxJQyAiLS8vVzNDLy9EVEQgU1ZHIDEuMS8vRU4iICJodHRwOi8vd3d3LnczLm9yZy9HcmFwaGljcy9TVkcvMS4xL0RURC9zdmcxMS5kdGQiPg0KPHN2ZyB3aWR0aD0iNjkycHQiIGhlaWdodD0iODc2cHQiIHZpZXdCb3g9IjAgMCA2OTIgODc2IiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+DQo8ZyBpZD0iIzhiNzVhMWZmIj4NCjxwYXRoIGZpbGw9IiM4Yjc1YTEiIG9wYWNpdHk9IjEuMDAiIGQ9IiBNIDI4MC4yOCAyLjc4IEMgMjk3LjE5IDIuMDMgMzE0LjA1IDAuODAgMzMwLjk4IDAuODIgQyAzNDMuMDAgMC4xNyAzNTUuMDEgMS4wOCAzNjcuMDQgMC43NSBDIDM3Ni40MiAwLjgyIDM4NS43NSAxLjgxIDM5NS4xMyAxLjkxIEMgNDA4LjU3IDIuNDIgNDIxLjkyIDMuOTQgNDM1LjM1IDQuNzUgQyA0NDYuMzIgNi41MiA0NTcuNDYgNi45NSA0NjguNDAgOC45MiBDIDQ3OS4wNCA5Ljg5IDQ4OS40NiAxMi4zNCA1MDAuMDEgMTMuOTMgQyA1MTEuOTggMTUuNzYgNTIzLjY1IDE5LjA2IDUzNS41NCAyMS4yOSBDIDU0OC44NCAyNC42MSA1NjIuMTIgMjguMTQgNTc1LjA1IDMyLjc0IEMgNTc1LjQ0IDMyLjc1IDU3Ni4yMiAzMi43NSA1NzYuNjAgMzIuNzYgQyA1ODMuMzEgMzUuNTIgNTkwLjM1IDM3LjM5IDU5Ny4wNCA0MC4yMSBDIDYwNS40NCA0My4zMiA2MTMuMjcgNDcuNzYgNjIxLjY4IDUwLjg3IEMgNjMyLjczIDU3LjExIDY0NC4zNyA2Mi4zOCA2NTQuNDMgNzAuMjUgQyA2NTguMzIgNzIuNjggNjYxLjc2IDc1LjcyIDY2NS4wNyA3OC44OCBDIDY3NS44MCA4Ny43NSA2ODQuNTkgOTkuMzUgNjg5LjE3IDExMi41NyBDIDY5My4xNiAxMjguMTMgNjkwLjQ2IDE0NC4yMSA2OTEuMzggMTYwLjAyIEMgNjkxLjcxIDE2OS4zOCA2OTAuNzkgMTc4Ljc0IDY5MS4zMSAxODguMTAgQyA2OTEuODAgMTk3LjM5IDY5MC44OSAyMDYuNjggNjkxLjIzIDIxNS45NyBDIDY5MS40OSAyMjEuOTYgNjkxLjUzIDIyNy45NyA2OTEuMjYgMjMzLjk3IEMgNjkwLjgzIDI0My42MiA2OTEuODEgMjUzLjI2IDY5MS4zMSAyNjIuOTEgQyA2OTAuNzcgMjcyLjkyIDY5MS44MiAyODIuOTQgNjkxLjMwIDI5Mi45NiBDIDY5MC43NyAzMDIuOTUgNjkxLjgyIDMxMi45NCA2OTEuMzAgMzIyLjk0IEMgNjkwLjc3IDMzMi45NSA2OTEuODIgMzQyLjk2IDY5MS4zMCAzNTIuOTcgQyA2OTAuNzcgMzYyLjk3IDY5MS44MiAzNzIuOTYgNjkxLjMwIDM4Mi45NiBDIDY5MC43NyAzOTIuOTUgNjkxLjgyIDQwMi45NCA2OTEuMzAgNDEyLjkzIEMgNjkwLjc3IDQyMi45NiA2OTEuODMgNDMyLjk5IDY5MS4zMCA0NDMuMDIgQyA2OTAuNzggNDUzLjAwIDY5MS44MiA0NjIuOTggNjkxLjMwIDQ3Mi45NSBDIDY5MC43NyA0ODIuOTUgNjkxLjgyIDQ5Mi45NSA2OTEuMzAgNTAyLjk0IEMgNjkwLjc3IDUxMi45NCA2OTEuODIgNTIyLjk0IDY5MS4zMCA1MzIuOTQgQyA2OTAuNzcgNTQyLjk0IDY5MS44MiA1NTIuOTMgNjkxLjMwIDU2Mi45MyBDIDY5MC43NyA1NzIuOTIgNjkxLjgyIDU4Mi45MiA2OTEuMzEgNTkyLjkxIEMgNjkwLjc4IDYwMi42MCA2OTEuNzcgNjEyLjI4IDY5MS4zNCA2MjEuOTggQyA2OTAuNzIgNjMyLjk4IDY5MS45MSA2NDMuOTkgNjkxLjIzIDY1NS4wMCBDIDY5MS4wOCA2NjYuNjcgNjkxLjc0IDY3OC4zNCA2OTEuMTYgNjkwLjAxIEMgNjkxLjY1IDcwMS42NyA2OTEuMTcgNzEzLjM0IDY5MS4yMiA3MjUuMDEgQyA2OTEuODQgNzM0LjAzIDY4OS40OSA3NDIuOTAgNjg2LjEwIDc1MS4xOCBDIDY4NC40NSA3NTUuMDUgNjgzLjIyIDc1OS4xNyA2ODAuNjggNzYyLjU4IEMgNjc1LjkyIDc2OS40MiA2NzEuODYgNzc2Ljk0IDY2NS40MyA3ODIuNDIgQyA2NjAuNjQgNzg2LjYyIDY1Ni44OSA3OTEuOTcgNjUxLjU0IDc5NS41MiBDIDY0OS4xNSA3OTcuMTkgNjQ2Ljc3IDc5OC44OCA2NDQuNTAgODAwLjcxIEMgNjM3LjY2IDgwNi44MyA2MjkuMjEgODEwLjY3IDYyMS42NiA4MTUuODAgQyA2MTEuNjggODIxLjAyIDYwMS45OCA4MjYuODUgNTkxLjQ3IDgzMC45NiBDIDU4Ni4wOSA4MzMuMDUgNTgwLjk4IDgzNS44NCA1NzUuNDYgODM3LjU1IEMgNTcwLjMwIDgzOS4yNCA1NjUuMjIgODQxLjE4IDU2MC4xOCA4NDMuMjIgQyA1NDMuODIgODQ5LjAyIDUyNi45NCA4NTMuMDkgNTEwLjE5IDg1Ny41MiBDIDQ5Ny43NyA4NjAuMjEgNDg1LjMxIDg2Mi43MCA0NzIuNzkgODY0Ljg1IEMgNDYyLjk4IDg2Ny4xNyA0NTIuOTAgODY3LjY5IDQ0Mi45NyA4NjkuMjkgQyA0MzIuNTEgODcxLjE5IDQyMS44NSA4NzEuNDMgNDExLjMwIDg3Mi42MyBDIDQwNC42MSA4NzMuNjUgMzk3LjgxIDg3My41MyAzOTEuMDcgODc0LjA3IEMgMzgzLjMzIDg3My45OCAzNzUuNjYgODc1LjU0IDM2Ny45MiA4NzQuODQgQyAzNjcuNjQgODc1LjEzIDM2Ny4xMCA4NzUuNzEgMzY2LjgzIDg3Ni4wMCBMIDM2Ni4zMCA4NzYuMDAgQyAzNjQuNzEgODc0Ljk2IDM2Mi43OCA4NzUuMDAgMzYwLjk4IDg3NS4wOSBDIDMzNy45NSA4NzUuNzcgMzE0Ljg5IDg3NS4yMCAyOTEuOTIgODczLjQxIEMgMjgxLjMzIDg3My4zNCAyNzAuODggODcxLjQwIDI2MC4zMyA4NzAuNzIgQyAyNTIuOTEgODcwLjE5IDI0NS41OSA4NjguODcgMjM4LjI0IDg2Ny43OCBDIDIyMC45MSA4NjUuODQgMjAzLjg1IDg2Mi4xNCAxODYuODIgODU4LjQ4IEMgMTc2LjUxIDg1Ni43NyAxNjYuNjIgODUzLjI3IDE1Ni40OSA4NTAuNzkgQyAxNTEuNTMgODUwLjAwIDE0Ni45OCA4NDcuNzkgMTQyLjE3IDg0Ni40OSBDIDEzMS42NiA4NDMuNzAgMTIxLjczIDgzOS4xOSAxMTEuNTMgODM1LjUwIEMgMTA1LjUxIDgzMy40NyAxMDAuMDEgODMwLjE3IDkzLjk3IDgyOC4xNiBDIDg2LjA0IDgyNC42NCA3OC42OCA4MjAuMDIgNzAuODYgODE2LjI3IEMgNjMuOTIgODEyLjA5IDU3LjE5IDgwNy41NyA1MC40NyA4MDMuMDYgQyA0NC44OSA3OTguODMgMzkuMzYgNzk0LjUxIDM0LjA2IDc4OS45NSBDIDI4LjE4IDc4My41NCAyMS4zNyA3NzcuODkgMTYuNjEgNzcwLjUyIEMgMTEuNzcgNzYzLjMyIDYuNzkgNzU1Ljk4IDQuNDkgNzQ3LjUyIEMgMi4xMiA3NDEuNTYgMS4wNiA3MzUuMjEgMC43MyA3MjguODMgTCAwLjAwIDczMC4wMSBMIDAuMDAgNzI4LjY5IEwgMC44MyA3MjguNjggQyAwLjgwIDcyMy4xMSAwLjkzIDcxNy41MyAwLjY2IDcxMS45NiBDIDAuMjMgNzAyLjI4IDEuMjIgNjkyLjYwIDAuNzAgNjgyLjkyIEMgMC4xOCA2NzIuOTQgMS4yMiA2NjIuOTUgMC43MCA2NTIuOTcgQyAwLjE4IDY0Mi45NyAxLjIyIDYzMi45NyAwLjcwIDYyMi45NyBDIDAuMTggNjEyLjk1IDEuMjMgNjAyLjkzIDAuNzAgNTkyLjkxIEMgMC4xOCA1ODIuOTEgMS4yMyA1NzIuOTAgMC42OSA1NjIuODkgQyAwLjE4IDU1Mi45MCAxLjIzIDU0Mi45MSAwLjcwIDUzMi45MiBDIDAuMTggNTIyLjkyIDEuMjMgNTEyLjkzIDAuNzAgNTAyLjkzIEMgMC4xOCA0OTIuOTQgMS4yMyA0ODIuOTUgMC43MCA0NzIuOTYgQyAwLjE4IDQ2Mi45NSAxLjIzIDQ1Mi45NSAwLjcwIDQ0Mi45NCBDIDAuMTggNDMyLjk3IDEuMjIgNDIyLjk5IDAuNzAgNDEzLjAyIEMgMC4xNyA0MDIuOTggMS4yMyAzOTIuOTQgMC42OSAzODIuOTAgQyAwLjE4IDM3Mi45MSAxLjIzIDM2Mi45MyAwLjcwIDM1Mi45NCBDIDAuMTggMzQyLjk0IDEuMjMgMzMyLjkzIDAuNzAgMzIyLjkyIEMgMC4xOCAzMTIuOTYgMS4yMiAzMDIuOTkgMC43MCAyOTMuMDIgQyAwLjE3IDI4My4wMSAxLjIyIDI3My4wMCAwLjcwIDI2Mi45OSBDIDAuMTcgMjUyLjk5IDEuMjIgMjQyLjk5IDAuNzAgMjMyLjk5IEMgMC4xNyAyMjIuOTYgMS4yMyAyMTIuOTMgMC42OSAyMDIuOTAgQyAwLjE4IDE5Mi45MiAxLjIyIDE4Mi45NCAwLjcwIDE3Mi45NSBDIDAuMTcgMTYyLjk0IDEuMjMgMTUyLjkzIDAuNjkgMTQyLjkyIEMgMC4zMCAxMzAuNjcgMC4xNCAxMTcuODkgNS4yNyAxMDYuNDYgQyA3LjAzIDEwMy40OCA4LjYxIDEwMC40MCAxMC4zOSA5Ny40MyBDIDE2LjI5IDg4LjI2IDI0LjIzIDgwLjY4IDMyLjYwIDczLjgwIEMgMzYuNDMgNzEuMzcgNDAuMTYgNjguNzggNDMuNjQgNjUuODcgQyA0NS44NCA2NC43MCA0Ny45NSA2My4zNSA1MC4wMCA2MS45MyBDIDU3LjAxIDU4LjQyIDYzLjY0IDU0LjE3IDcwLjgwIDUwLjkzIEMgNzUuNzMgNDguNjggODAuNjYgNDYuNDQgODUuNDUgNDMuOTIgQyA4OC45MyA0Mi44MSA5Mi4yMyA0MS4yNCA5NS42NCAzOS45MyBDIDk5LjAzIDM5LjAxIDEwMi4yMyAzNy41NyAxMDUuNDcgMzYuMjMgQyAxMTguMDcgMzIuNjEgMTMwLjI5IDI3LjY1IDE0My4xNCAyNC45MSBDIDE0Ny43NCAyMy44OCAxNTIuMTEgMjEuOTMgMTU2LjgxIDIxLjM1IEMgMTY3LjgyIDE5LjA3IDE3OC42NCAxNS45MSAxODkuODEgMTQuNDQgQyAyMDcuMzkgMTAuNzMgMjI1LjIzIDguNTUgMjQzLjA1IDYuNDYgQyAyNTUuMzggNC40MCAyNjcuOTEgNC40MSAyODAuMjggMi43OCBNIDMyMy4xNyAyNC4zNCBDIDI4NC42NSAyNS4yMSAyNDYuMTQgMjguNTAgMjA4LjA5IDM0LjY5IEMgMTY4LjY0IDQxLjI3IDEyOS40NCA1MC44OCA5Mi41OCA2Ni42MCBDIDg1LjU3IDY5LjkyIDc4LjI1IDcyLjYyIDcxLjY0IDc2Ljc0IEMgNTYuODkgODQuODMgNDIuMTcgOTQuMTMgMzEuNzkgMTA3LjY1IEMgMjUuODQgMTE1LjM3IDIxLjg4IDEyNS44OCAyNS40NiAxMzUuNDggQyAzMC4xMSAxNDkuMTAgNDEuNzEgMTU4Ljc1IDUyLjk5IDE2Ni45NCBDIDY0LjQ4IDE3NC42MiA3Ni41MiAxODEuNTggODkuMzAgMTg2Ljg5IEMgMTEzLjkwIDE5OC4wMyAxMzkuOTMgMjA1LjY0IDE2Ni4xMyAyMTIuMDIgQyAyMDUuNDkgMjIxLjEyIDI0NS42NiAyMjYuNDggMjg1Ljk2IDIyOS4wOSBDIDM0My45OCAyMzIuNzUgNDAyLjM4IDIzMS4yOCA0NjAuMDcgMjIzLjg2IEMgNDk4LjI2IDIxOC43NiA1MzYuMjIgMjExLjAyIDU3Mi43OCAxOTguNjkgQyA1OTguOTYgMTg5LjM4IDYyNS4yMCAxNzguNTEgNjQ2Ljk4IDE2MC44OCBDIDY1Ni45NSAxNTIuNDggNjY3LjA5IDE0MS44MCA2NjcuOTcgMTI4LjA4IEMgNjY4LjEwIDExOC40OSA2NjIuNjEgMTA5Ljg1IDY1Ni4yOCAxMDMuMDUgQyA2NTEuNTIgOTcuMjYgNjQ1LjM5IDkyLjg3IDYzOS40NiA4OC4zNyBDIDYyNy4yNyA4MC4wNiA2MTQuMzAgNzIuOTIgNjAwLjcyIDY3LjE2IEMgNTU4LjQ1IDQ5LjAyIDUxMy4yMyAzOC43OCA0NjcuODQgMzIuMjUgQyA0MzAuNDcgMjYuODQgMzkyLjcxIDI0LjU5IDM1NC45OSAyNC4wMCBDIDM0NC4zOCAyNC4xNyAzMzMuNzYgMjMuNjMgMzIzLjE3IDI0LjM0IE0gMjMuOTkgMTczLjIyIEMgMjQuMDEgMjI0LjQ3IDI0LjAwIDI3NS43MiAyMy45OSAzMjYuOTYgQyAyNC4wMyAzMzcuMDIgMjguNjUgMzQ2LjQ0IDM0Ljc0IDM1NC4yMiBDIDQxLjEwIDM2MS44NyA0OC42MiAzNjguNDUgNTYuNzAgMzc0LjI1IEMgNjcuNzggMzgxLjYzIDc5LjI3IDM4OC41NSA5MS42NCAzOTMuNTcgQyAxMTkuNTEgNDA2LjM1IDE0OS4xOSA0MTQuNzAgMTc5LjA3IDQyMS4zMSBDIDIxNC45NCA0MjkuMDYgMjUxLjQzIDQzMy43NSAyODguMDQgNDM2LjA5IEMgMzU1LjA3IDQ0MC40NSA0MjIuNjggNDM3LjUxIDQ4OC45MiA0MjYuMDcgQyA1MzEuNzEgNDE4LjIyIDU3NC4zOCA0MDcuMTUgNjEzLjM5IDM4Ny41MCBDIDYyNi44MyAzODAuMTYgNjQwLjE2IDM3Mi4xNSA2NTAuOTUgMzYxLjE1IEMgNjYwLjIwIDM1Mi4xNyA2NjcuOTYgMzQwLjMwIDY2OC4wMCAzMjcuMDAgQyA2NjguMDAgMjc1Ljc0IDY2Ny45OSAyMjQuNDggNjY4LjAxIDE3My4yMiBDIDY1Ni4yMCAxODMuNzIgNjQzLjA2IDE5Mi43MiA2MjguOTQgMTk5LjgxIEMgNTk3LjYyIDIxNi4yOCA1NjMuNDkgMjI2LjYwIDUyOS4xNyAyMzQuNzMgQyA0ODQuNDkgMjQ0LjkxIDQzOC44NCAyNTAuNTIgMzkzLjA5IDI1Mi44OCBDIDMxNi43MSAyNTYuNDcgMjM5LjYzIDI1MS43NCAxNjQuODUgMjM1LjIzIEMgMTMwLjEyIDIyNy4wMSA5NS41MCAyMTYuODIgNjMuNzcgMjAwLjE2IEMgNDkuMzggMTkzLjAzIDM2LjAzIDE4My44NCAyMy45OSAxNzMuMjIgTSAyNC4wNyAzNzcuMTkgQyAyMy45MSA0MjIuMTIgMjQuMDQgNDY3LjA1IDI0LjAwIDUxMS45OCBDIDI0LjIxIDUyNy4yNSAzMS4xNCA1NDEuNjQgNDAuNzYgNTUzLjIyIEMgNTAuODIgNTY1LjAwIDYyLjgyIDU3NS4wNyA3NS45OCA1ODMuMjIgQyA5Ny4xOCA1OTYuODAgMTIwLjQ4IDYwNi43MyAxNDQuMjMgNjE0Ljg5IEMgMTg5LjcyIDYzMC4yNyAyMzcuMzMgNjM4LjU1IDI4NS4xMCA2NDIuNTcgQyAzMTQuMzQgNjQ0LjgwIDM0My42OSA2NDUuNzcgMzcyLjk5IDY0NC40NyBDIDQxNi42OSA2NDIuOTggNDYwLjMyIDYzNy42OSA1MDIuOTEgNjI3LjcyIEMgNTM2LjA4IDYxOS44MSA1NjguNzYgNjA5LjAxIDU5OS4wNSA1OTMuMTkgQyA2MTUuNDUgNTg0LjI1IDYzMS41MiA1NzQuMTIgNjQ0LjQ3IDU2MC40OSBDIDY1My4wMiA1NTIuNTUgNjU5LjU4IDU0Mi41OSA2NjQuMTUgNTMxLjg4IEMgNjY2LjkxIDUyNC42MCA2NjguMTIgNTE2Ljc3IDY2OC4wMSA1MDkuMDAgQyA2NjcuOTMgNDY1LjA2IDY2OC4xMiA0MjEuMTIgNjY3LjkxIDM3Ny4xOSBDIDY2Ny40MCAzNzcuNTggNjY2LjM3IDM3OC4zNyA2NjUuODYgMzc4Ljc3IEMgNjUzLjc5IDM5MC41MiA2MzkuNDUgMzk5Ljc1IDYyNC41NiA0MDcuNTEgQyA2MDEuNDIgNDE5LjYyIDU3Ni42NyA0MjguMzIgNTUxLjYwIDQzNS41MCBDIDQ5OS4yNSA0NDkuOTYgNDQ1LjEyIDQ1Ny4xNSAzOTAuOTYgNDU5Ljk2IEMgMzIzLjQyIDQ2My4wOSAyNTUuNDAgNDU5LjQ1IDE4OC44OSA0NDYuODkgQyAxNTAuNzAgNDM5LjMzIDExMi43NyA0MjkuMDQgNzcuNDIgNDEyLjUwIEMgNjEuMjcgNDA0Ljc3IDQ1LjYyIDM5NS43NSAzMS44OSAzODQuMTkgQyAyOS4yNCAzODEuOTAgMjcuMDAgMzc5LjE2IDI0LjA3IDM3Ny4xOSBNIDI0LjA4IDU2OS4xNyBDIDIzLjkwIDYyMS4xMiAyNC4wNCA2NzMuMDcgMjQuMDEgNzI1LjAyIEMgMjMuODcgNzM1LjMwIDI4LjEwIDc0NC45MiAzMy4yNiA3NTMuNTcgQyAzOC42NSA3NjEuNDIgNDQuOTkgNzY4LjYyIDUyLjMxIDc3NC43NCBDIDY1Ljc5IDc4Ni4xOCA4MC45NCA3OTUuNjAgOTYuODkgODAzLjE4IEMgMTE2LjQxIDgxMy4xMyAxMzcuMTEgODIwLjU4IDE1OC4wNSA4MjYuOTQgQyAyMDIuNTggODQwLjEzIDI0OC43NyA4NDcuMTcgMjk1LjA0IDg1MC4zNiBDIDM1Ni40NCA4NTQuMjMgNDE4LjQyIDg1MS40NiA0NzguOTQgODQwLjExIEMgNTE0LjEwIDgzMy41NiA1NDguNzUgODIzLjgzIDU4MS42MyA4MDkuNjcgQyA2MDEuOTcgODAwLjQ2IDYyMi4wNyA3ODkuOTggNjM5LjAwIDc3NS4yMSBDIDY1MS4xMyA3NjUuMjAgNjYxLjc3IDc1Mi40NSA2NjYuMjkgNzM3LjE1IEMgNjY3LjczIDczMi41OSA2NjguMDkgNzI3Ljc4IDY2OC4wMSA3MjMuMDMgQyA2NjcuOTQgNjcxLjczIDY2OC4xMCA2MjAuNDMgNjY3LjkzIDU2OS4xNCBDIDY1MC42NCA1ODkuMzYgNjI4LjA1IDYwNC40OCA2MDQuMzAgNjE2LjEzIEMgNTg3LjU3IDYyNC45NyA1NjkuODYgNjMxLjg2IDU1MS44OSA2MzcuNzEgQyA1MjguNDYgNjQ1LjcxIDUwNC4zMiA2NTEuNDYgNDgwLjAyIDY1Ni4wOCBDIDQxOS43NSA2NjcuMTMgMzU4LjExIDY3MC4zMiAyOTYuOTggNjY2LjQ4IEMgMjU1LjA0IDY2My43OSAyMTMuMjYgNjU3Ljc4IDE3Mi41MiA2NDcuMzggQyAxNDYuMjcgNjQwLjM1IDEyMC4zMCA2MzEuODcgOTUuNjkgNjIwLjI1IEMgNjkuMTYgNjA3LjU3IDQzLjMzIDU5MS43NCAyNC4wOCA1NjkuMTcgWiIgLz4NCjwvZz4NCjxnIGlkPSIjZGNkNWYzZmYiPg0KPHBhdGggZmlsbD0iI2RjZDVmMyIgb3BhY2l0eT0iMS4wMCIgZD0iIE0gMzIzLjE3IDI0LjM0IEMgMzMzLjc2IDIzLjYzIDM0NC4zOCAyNC4xNyAzNTQuOTkgMjQuMDAgQyAzOTIuNzEgMjQuNTkgNDMwLjQ3IDI2Ljg0IDQ2Ny44NCAzMi4yNSBDIDUxMy4yMyAzOC43OCA1NTguNDUgNDkuMDIgNjAwLjcyIDY3LjE2IEMgNjE0LjMwIDcyLjkyIDYyNy4yNyA4MC4wNiA2MzkuNDYgODguMzcgQyA2NDUuMzkgOTIuODcgNjUxLjUyIDk3LjI2IDY1Ni4yOCAxMDMuMDUgQyA2NjIuNjEgMTA5Ljg1IDY2OC4xMCAxMTguNDkgNjY3Ljk3IDEyOC4wOCBDIDY2Ny4wOSAxNDEuODAgNjU2Ljk1IDE1Mi40OCA2NDYuOTggMTYwLjg4IEMgNjI1LjIwIDE3OC41MSA1OTguOTYgMTg5LjM4IDU3Mi43OCAxOTguNjkgQyA1MzYuMjIgMjExLjAyIDQ5OC4yNiAyMTguNzYgNDYwLjA3IDIyMy44NiBDIDQwMi4zOCAyMzEuMjggMzQzLjk4IDIzMi43NSAyODUuOTYgMjI5LjA5IEMgMjQ1LjY2IDIyNi40OCAyMDUuNDkgMjIxLjEyIDE2Ni4xMyAyMTIuMDIgQyAxMzkuOTMgMjA1LjY0IDExMy45MCAxOTguMDMgODkuMzAgMTg2Ljg5IEMgNzYuNTIgMTgxLjU4IDY0LjQ4IDE3NC42MiA1Mi45OSAxNjYuOTQgQyA0MS43MSAxNTguNzUgMzAuMTEgMTQ5LjEwIDI1LjQ2IDEzNS40OCBDIDIxLjg4IDEyNS44OCAyNS44NCAxMTUuMzcgMzEuNzkgMTA3LjY1IEMgNDIuMTcgOTQuMTMgNTYuODkgODQuODMgNzEuNjQgNzYuNzQgQyA3OC4yNSA3Mi42MiA4NS41NyA2OS45MiA5Mi41OCA2Ni42MCBDIDEyOS40NCA1MC44OCAxNjguNjQgNDEuMjcgMjA4LjA5IDM0LjY5IEMgMjQ2LjE0IDI4LjUwIDI4NC42NSAyNS4yMSAzMjMuMTcgMjQuMzQgWiIgLz4NCjxwYXRoIGZpbGw9IiNkY2Q1ZjMiIG9wYWNpdHk9IjEuMDAiIGQ9IiBNIDIzLjk5IDE3My4yMiBDIDM2LjAzIDE4My44NCA0OS4zOCAxOTMuMDMgNjMuNzcgMjAwLjE2IEMgOTUuNTAgMjE2LjgyIDEzMC4xMiAyMjcuMDEgMTY0Ljg1IDIzNS4yMyBDIDIzOS42MyAyNTEuNzQgMzE2LjcxIDI1Ni40NyAzOTMuMDkgMjUyLjg4IEMgNDM4Ljg0IDI1MC41MiA0ODQuNDkgMjQ0LjkxIDUyOS4xNyAyMzQuNzMgQyA1NjMuNDkgMjI2LjYwIDU5Ny42MiAyMTYuMjggNjI4Ljk0IDE5OS44MSBDIDY0My4wNiAxOTIuNzIgNjU2LjIwIDE4My43MiA2NjguMDEgMTczLjIyIEMgNjY3Ljk5IDIyNC40OCA2NjguMDAgMjc1Ljc0IDY2OC4wMCAzMjcuMDAgQyA2NjcuOTYgMzQwLjMwIDY2MC4yMCAzNTIuMTcgNjUwLjk1IDM2MS4xNSBDIDY0MC4xNiAzNzIuMTUgNjI2LjgzIDM4MC4xNiA2MTMuMzkgMzg3LjUwIEMgNTc0LjM4IDQwNy4xNSA1MzEuNzEgNDE4LjIyIDQ4OC45MiA0MjYuMDcgQyA0MjIuNjggNDM3LjUxIDM1NS4wNyA0NDAuNDUgMjg4LjA0IDQzNi4wOSBDIDI1MS40MyA0MzMuNzUgMjE0Ljk0IDQyOS4wNiAxNzkuMDcgNDIxLjMxIEMgMTQ5LjE5IDQxNC43MCAxMTkuNTEgNDA2LjM1IDkxLjY0IDM5My41NyBDIDc5LjI3IDM4OC41NSA2Ny43OCAzODEuNjMgNTYuNzAgMzc0LjI1IEMgNDguNjIgMzY4LjQ1IDQxLjEwIDM2MS44NyAzNC43NCAzNTQuMjIgQyAyOC42NSAzNDYuNDQgMjQuMDMgMzM3LjAyIDIzLjk5IDMyNi45NiBDIDI0LjAwIDI3NS43MiAyNC4wMSAyMjQuNDcgMjMuOTkgMTczLjIyIFoiIC8+DQo8cGF0aCBmaWxsPSIjZGNkNWYzIiBvcGFjaXR5PSIxLjAwIiBkPSIgTSAyNC4wNyAzNzcuMTkgQyAyNy4wMCAzNzkuMTYgMjkuMjQgMzgxLjkwIDMxLjg5IDM4NC4xOSBDIDQ1LjYyIDM5NS43NSA2MS4yNyA0MDQuNzcgNzcuNDIgNDEyLjUwIEMgMTEyLjc3IDQyOS4wNCAxNTAuNzAgNDM5LjMzIDE4OC44OSA0NDYuODkgQyAyNTUuNDAgNDU5LjQ1IDMyMy40MiA0NjMuMDkgMzkwLjk2IDQ1OS45NiBDIDQ0NS4xMiA0NTcuMTUgNDk5LjI1IDQ0OS45NiA1NTEuNjAgNDM1LjUwIEMgNTc2LjY3IDQyOC4zMiA2MDEuNDIgNDE5LjYyIDYyNC41NiA0MDcuNTEgQyA2MzkuNDUgMzk5Ljc1IDY1My43OSAzOTAuNTIgNjY1Ljg2IDM3OC43NyBDIDY2Ni4zNyAzNzguMzcgNjY3LjQwIDM3Ny41OCA2NjcuOTEgMzc3LjE5IEMgNjY4LjEyIDQyMS4xMiA2NjcuOTMgNDY1LjA2IDY2OC4wMSA1MDkuMDAgQyA2NjguMTIgNTE2Ljc3IDY2Ni45MSA1MjQuNjAgNjY0LjE1IDUzMS44OCBDIDY1OS41OCA1NDIuNTkgNjUzLjAyIDU1Mi41NSA2NDQuNDcgNTYwLjQ5IEMgNjMxLjUyIDU3NC4xMiA2MTUuNDUgNTg0LjI1IDU5OS4wNSA1OTMuMTkgQyA1NjguNzYgNjA5LjAxIDUzNi4wOCA2MTkuODEgNTAyLjkxIDYyNy43MiBDIDQ2MC4zMiA2MzcuNjkgNDE2LjY5IDY0Mi45OCAzNzIuOTkgNjQ0LjQ3IEMgMzQzLjY5IDY0NS43NyAzMTQuMzQgNjQ0LjgwIDI4NS4xMCA2NDIuNTcgQyAyMzcuMzMgNjM4LjU1IDE4OS43MiA2MzAuMjcgMTQ0LjIzIDYxNC44OSBDIDEyMC40OCA2MDYuNzMgOTcuMTggNTk2LjgwIDc1Ljk4IDU4My4yMiBDIDYyLjgyIDU3NS4wNyA1MC44MiA1NjUuMDAgNDAuNzYgNTUzLjIyIEMgMzEuMTQgNTQxLjY0IDI0LjIxIDUyNy4yNSAyNC4wMCA1MTEuOTggQyAyNC4wNCA0NjcuMDUgMjMuOTEgNDIyLjEyIDI0LjA3IDM3Ny4xOSBaIiAvPg0KPHBhdGggZmlsbD0iI2RjZDVmMyIgb3BhY2l0eT0iMS4wMCIgZD0iIE0gMjQuMDggNTY5LjE3IEMgNDMuMzMgNTkxLjc0IDY5LjE2IDYwNy41NyA5NS42OSA2MjAuMjUgQyAxMjAuMzAgNjMxLjg3IDE0Ni4yNyA2NDAuMzUgMTcyLjUyIDY0Ny4zOCBDIDIxMy4yNiA2NTcuNzggMjU1LjA0IDY2My43OSAyOTYuOTggNjY2LjQ4IEMgMzU4LjExIDY3MC4zMiA0MTkuNzUgNjY3LjEzIDQ4MC4wMiA2NTYuMDggQyA1MDQuMzIgNjUxLjQ2IDUyOC40NiA2NDUuNzEgNTUxLjg5IDYzNy43MSBDIDU2OS44NiA2MzEuODYgNTg3LjU3IDYyNC45NyA2MDQuMzAgNjE2LjEzIEMgNjI4LjA1IDYwNC40OCA2NTAuNjQgNTg5LjM2IDY2Ny45MyA1NjkuMTQgQyA2NjguMTAgNjIwLjQzIDY2Ny45NCA2NzEuNzMgNjY4LjAxIDcyMy4wMyBDIDY2OC4wOSA3MjcuNzggNjY3LjczIDczMi41OSA2NjYuMjkgNzM3LjE1IEMgNjYxLjc3IDc1Mi40NSA2NTEuMTMgNzY1LjIwIDYzOS4wMCA3NzUuMjEgQyA2MjIuMDcgNzg5Ljk4IDYwMS45NyA4MDAuNDYgNTgxLjYzIDgwOS42NyBDIDU0OC43NSA4MjMuODMgNTE0LjEwIDgzMy41NiA0NzguOTQgODQwLjExIEMgNDE4LjQyIDg1MS40NiAzNTYuNDQgODU0LjIzIDI5NS4wNCA4NTAuMzYgQyAyNDguNzcgODQ3LjE3IDIwMi41OCA4NDAuMTMgMTU4LjA1IDgyNi45NCBDIDEzNy4xMSA4MjAuNTggMTE2LjQxIDgxMy4xMyA5Ni44OSA4MDMuMTggQyA4MC45NCA3OTUuNjAgNjUuNzkgNzg2LjE4IDUyLjMxIDc3NC43NCBDIDQ0Ljk5IDc2OC42MiAzOC42NSA3NjEuNDIgMzMuMjYgNzUzLjU3IEMgMjguMTAgNzQ0LjkyIDIzLjg3IDczNS4zMCAyNC4wMSA3MjUuMDIgQyAyNC4wNCA2NzMuMDcgMjMuOTAgNjIxLjEyIDI0LjA4IDU2OS4xNyBaIiAvPg0KPC9nPg0KPC9zdmc+DQo=');
    }
    .bkp-link.db[href$='.sql.gz']::before{
        background-image: url('data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiID8+DQo8IURPQ1RZUEUgc3ZnIFBVQkxJQyAiLS8vVzNDLy9EVEQgU1ZHIDEuMS8vRU4iICJodHRwOi8vd3d3LnczLm9yZy9HcmFwaGljcy9TVkcvMS4xL0RURC9zdmcxMS5kdGQiPg0KPHN2ZyB3aWR0aD0iNzUxcHQiIGhlaWdodD0iOTU5cHQiIHZpZXdCb3g9IjAgMCA3NTEgOTU5IiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+DQo8ZyBpZD0iI2JkYzNjN2ZmIj4NCjxwYXRoIGZpbGw9IiNiZGMzYzciIG9wYWNpdHk9IjEuMDAiIGQ9IiBNIDc4LjgyIDAuMDAgTCAyOTIuMDAgMC4wMCBDIDI5Mi4wMCAxMzkuMzMgMjkxLjk5IDI3OC42NiAyOTIuMDAgNDE4LjAwIEMgMjkyLjEwIDQ0MS4yMCAzMDIuODUgNDYzLjg0IDMyMC4xMyA0NzkuMjAgQyAzMDIuMzQgNDk0LjgzIDI5MS42NiA1MTguMjggMjkxLjk4IDU0Mi4wMCBDIDI5Mi4wMiA1OTcuMzUgMjkxLjk5IDY1Mi43MSAyOTIuMDAgNzA4LjA2IEMgMjkyLjA3IDcxMS44MCAyOTIuNTAgNzE1LjUyIDI5Mi43MyA3MTkuMjYgQyAyOTIuMjAgNzIzLjE3IDI5MS43OCA3MjcuMTEgMjkxLjk1IDczMS4wNyBDIDI5Mi4yOSA3NDMuOTUgMjkwLjk2IDc1Ny4wOSAyOTQuMzEgNzY5LjcxIEMgMzAwLjk0IDc5Ny45MCAzMjMuMzkgODIxLjYzIDM1MS4xNyA4MjkuODEgQyAzNzIuMjggODM2LjI1IDM5NS45NCA4MzMuODkgNDE1LjI2IDgyMy4xOSBDIDQzNS4yMyA4MTIuMzQgNDUwLjQwIDc5My4wMiA0NTYuMDQgNzcwLjk5IEMgNDU4LjI1IDc2Mi44NSA0NTguODUgNzU0LjM4IDQ1OC42OSA3NDUuOTggQyA0NTguNTAgNzM2LjQ4IDQ1OS4wOSA3MjYuOTcgNDU4LjIyIDcxNy41MCBDIDQ1OC45MiA3MDcuNjkgNDU4LjYwIDY5Ny44NiA0NTguNjYgNjg4LjA0IEMgNDU4LjY2IDYzOS4wMiA0NTguNjYgNTkwLjAwIDQ1OC42NyA1NDAuOTkgQyA0NTguNzMgNTE3LjYxIDQ0OC4wNiA0OTQuNjQgNDMwLjU2IDQ3OS4yMiBDIDQ0My45NyA0NjcuMTEgNDUzLjY2IDQ1MC44MSA0NTcuMTAgNDMzLjAyIEMgNDU4LjgxIDQyNS4xMyA0NTguNzUgNDE3LjAzIDQ1OC42NyA0MDkuMDAgQyA0NTguNjcgMjc4LjM2IDQ1OC42NyAxNDcuNzEgNDU4LjY3IDE3LjA2IEMgNDU4LjcwIDExLjM4IDQ1OC40NSA1LjY4IDQ1OC45MiAwLjAwIEwgNTAwLjI2IDAuMDAgQyA1MDAuNDMgNTEuMDAgNTAwLjI4IDEwMi4wMCA1MDAuMzMgMTUzLjAwIEMgNTAwLjQyIDE2Mi43MCA0OTkuNzggMTcyLjUyIDUwMS43MiAxODIuMTAgQyA1MDUuMjUgMjAxLjIyIDUxNS44MiAyMTguODkgNTMwLjg4IDIzMS4xNyBDIDU0Ni4xOCAyNDMuODQgNTY2LjE0IDI1MC42MiA1ODYuMDAgMjUwLjAyIEMgNjQwLjc2IDI0OS44NiA2OTUuNTIgMjUwLjIzIDc1MC4yNyAyNDkuODMgTCA3NTEuMDAgMjQ5Ljk4IEwgNzUxLjAwIDgzNy45NiBDIDc0OS4wMiA4NDcuMzEgNzQ3Ljg3IDg1Ni45NCA3NDMuODQgODY1LjcyIEMgNzMyLjEzIDg5NC4wNyA3MDMuNzIgOTE0LjY2IDY3My4wNCA5MTYuNDggQyA2NjIuMDQgOTE2LjkyIDY1MS4wMyA5MTYuNTUgNjQwLjAyIDkxNi42NyBDIDQ2Mi4zNSA5MTYuNjcgMjg0LjY5IDkxNi42NyAxMDcuMDIgOTE2LjY3IEMgOTIuMzIgOTE2LjMwIDc3LjMzIDkxOC4wMiA2Mi45NSA5MTQuMTIgQyAzOC40MSA5MDcuOTUgMTcuMjggODg5LjkwIDcuMjQgODY2LjY4IEMgNC4yMyA4NjAuNDIgMy4yNyA4NTMuNDUgMC40NCA4NDcuMTMgQyAwLjE5IDc4Ni43NiAwLjQwIDcyNi4zOSAwLjMzIDY2Ni4wMiBDIDAuMzMgNDcxLjM1IDAuMzQgMjc2LjY5IDAuMzMgODIuMDIgQyAwLjQ3IDU4LjA1IDExLjg2IDM0LjU2IDMwLjMwIDE5LjMyIEMgNDMuODUgNy44NyA2MS4xNSAxLjE2IDc4LjgyIDAuMDAgWiIgLz4NCjxwYXRoIGZpbGw9IiNiZGMzYzciIG9wYWNpdHk9IjEuMDAiIGQ9IiBNIDM1Mi4yMiA1NDguMjEgQyAzNjQuMTUgNTQwLjY2IDM3OS44MCA1MzkuNzEgMzkyLjczIDU0NS4yMSBDIDQwMC42MiA1NDguMzYgNDA2Ljc1IDU1NC41MiA0MTEuNTkgNTYxLjM1IEMgNDA0LjIzIDU3My44MCAzOTAuNzkgNTgzLjE0IDM3Ni4wMyA1ODMuMjcgQyAzNjAuODIgNTgzLjcwIDM0Ni43MCA1NzQuMjYgMzM5LjEzIDU2MS40MiBDIDM0Mi43NCA1NTYuMzUgMzQ2LjgzIDU1MS40NiAzNTIuMjIgNTQ4LjIxIFoiIC8+DQo8cGF0aCBmaWxsPSIjYmRjM2M3IiBvcGFjaXR5PSIxLjAwIiBkPSIgTSAzNDQuMTAgNjgwLjA0IEMgMzU4LjQ0IDY2My45MiAzODUuNDYgNjYyLjMyIDQwMi4wOCA2NzUuODEgQyA0MTAuNDMgNjgyLjI3IDQxNS4wOSA2OTIuMzAgNDE2LjkxIDcwMi41MSBDIDQxNy41NSA3MTAuNjUgNDE2LjI3IDcxOS4wNiA0MTIuMzkgNzI2LjMyIEMgNDA1LjY0IDczOS40NSAzOTIuMDAgNzQ5LjMxIDM3Ny4wMyA3NDkuOTAgQyAzNjQuNjggNzUwLjU3IDM1Mi41OCA3NDQuNjQgMzQ0LjU0IDczNS40NSBDIDMzNi4wNSA3MjYuMTQgMzMyLjM1IDcxMy4wOCAzMzMuNzYgNzAwLjY4IEMgMzM2LjA2IDY5My4zMSAzMzguNjYgNjg1LjczIDM0NC4xMCA2ODAuMDQgWiIgLz4NCjwvZz4NCjxnIGlkPSIjN2Y4YzhkZmYiPg0KPHBhdGggZmlsbD0iIzdmOGM4ZCIgb3BhY2l0eT0iMS4wMCIgZD0iIE0gMjkyLjAwIDAuMDAgTCAzMzMuODQgMC4wMCBDIDMzMy40MyAxMy44OSAzMzMuNzggMjcuNzkgMzMzLjY3IDQxLjY4IEMgMzMzLjY5IDU1LjU2IDMzMy43MiA2OS40NCAzMzMuNjggODMuMzIgQyAzMzMuNjYgOTcuMjEgMzMzLjY3IDExMS4xMSAzMzMuNjcgMTI1LjAwIEMgMzMzLjcwIDEzOC44OSAzMzMuNzEgMTUyLjc4IDMzMy42NyAxNjYuNjcgQyAzMzMuNjcgMTgwLjU2IDMzMy42NyAxOTQuNDQgMzMzLjY3IDIwOC4zMyBDIDMzMy43MSAyMjIuMjIgMzMzLjcwIDIzNi4xMSAzMzMuNjggMjUwLjAwIEMgMzMzLjY2IDI2My44OSAzMzMuNjcgMjc3Ljc5IDMzMy42NyAyOTEuNjggQyAzMzMuNzAgMzA1LjU2IDMzMy43MSAzMTkuNDQgMzMzLjY4IDMzMy4zMiBDIDMzMy42NiAzNDcuMjEgMzMzLjY3IDM2MS4xMCAzMzMuNjcgMzc1LjAwIEMgMzMzLjcwIDM4OC44OSAzMzMuNzMgNDAyLjc5IDMzMy42MyA0MTYuNjggQyAzMzMuNzIgNDM3LjQyIDM1MS4xMyA0NTYuMDQgMzcxLjYwIDQ1OC4zNSBDIDM1Mi43MiA0NTkuMTQgMzM0LjI1IDQ2Ni42NSAzMjAuMTMgNDc5LjIwIEMgMzAyLjg1IDQ2My44NCAyOTIuMTAgNDQxLjIwIDI5Mi4wMCA0MTguMDAgQyAyOTEuOTkgMjc4LjY2IDI5Mi4wMCAxMzkuMzMgMjkyLjAwIDAuMDAgWiIgLz4NCjxwYXRoIGZpbGw9IiM3ZjhjOGQiIG9wYWNpdHk9IjEuMDAiIGQ9IiBNIDQxNy4wMCAwLjAwIEwgNDU4LjkyIDAuMDAgQyA0NTguNDUgNS42OCA0NTguNzAgMTEuMzggNDU4LjY3IDE3LjA2IEMgNDU4LjY3IDE0Ny43MSA0NTguNjcgMjc4LjM2IDQ1OC42NyA0MDkuMDAgQyA0NTguNzUgNDE3LjAzIDQ1OC44MSA0MjUuMTMgNDU3LjEwIDQzMy4wMiBDIDQ1My42NiA0NTAuODEgNDQzLjk3IDQ2Ny4xMSA0MzAuNTYgNDc5LjIyIEMgNDE3Ljc2IDQ2Ny45NSA0MDEuNDcgNDYwLjQ1IDM4NC40MyA0NTguODggQyAzODQuNDcgNDU4LjUxIDM4NC41NiA0NTcuNzYgMzg0LjYxIDQ1Ny4zOSBDIDM4OS41MSA0NTUuNjkgMzk0LjQ0IDQ1My44MiAzOTguNjQgNDUwLjcxIEMgNDA5LjU0IDQ0My4wMiA0MTYuODEgNDMwLjI1IDQxNy4xMyA0MTYuODMgQyA0MDMuMjMgNDE2LjQxIDM4OS4zMiA0MTYuOTggMzc1LjQzIDQxNi41NSBDIDM3NS4yMCA0MDIuNzkgMzc1LjI1IDM4OS4wMiAzNzUuNDAgMzc1LjI2IEMgMzg5LjIzIDM3NC43NCA0MDMuMDcgMzc1LjM1IDQxNi45MSAzNzQuOTkgQyA0MTcuMDQgMzYxLjEwIDQxNy4xMiAzNDcuMjEgNDE2Ljg4IDMzMy4zMiBDIDQwMy4xMSAzMzMuMTkgMzg5LjM0IDMzMy40NyAzNzUuNTcgMzMzLjIyIEMgMzc0Ljk2IDMxOS41NSAzNzUuNDQgMzA1Ljg1IDM3NS4zMiAyOTIuMTcgQyAzODkuMTUgMjkxLjIxIDQwMy4wNCAyOTIuMDkgNDE2LjkwIDI5MS43NSBDIDQxNy4wOSAyNzcuODUgNDE3LjA2IDI2My45NSA0MTYuOTIgMjUwLjA1IEMgNDAzLjA3IDI0OS43OSAzODkuMjEgMjUwLjI4IDM3NS4zNiAyNDkuODMgQyAzNzUuMzEgMjM2LjA2IDM3NS4xOCAyMjIuMjggMzc1LjQzIDIwOC41MCBDIDM4OS4yNiAyMDguMTIgNDAzLjEwIDIwOC42NyA0MTYuOTMgMjA4LjI1IEMgNDE3LjA2IDE5NC40NSA0MTcuMDQgMTgwLjY1IDQxNi45MyAxNjYuODUgQyA0MDMuMTEgMTY2LjQwIDM4OS4yNyAxNjYuOTcgMzc1LjQ1IDE2Ni41OCBDIDM3NS4xNSAxNTIuODEgMzc1LjMwIDEzOS4wNCAzNzUuMzcgMTI1LjI3IEMgMzg5LjIyIDEyNC43NyA0MDMuMDkgMTI1LjMzIDQxNi45NCAxMjQuOTkgQyA0MTYuOTkgMTExLjExIDQxNy4xNiA5Ny4yMSA0MTYuODUgODMuMzMgQyA0MDMuMDIgODMuMDQgMzg5LjE3IDgzLjc3IDM3NS4zNSA4Mi45NyBDIDM3NS4yNiA2OS4zNCAzNzUuMjggNTUuNzEgMzc1LjM0IDQyLjA4IEMgMzg5LjE3IDQxLjM1IDQwMy4wMyA0Mi4wMSA0MTYuODggNDEuNzYgQyA0MTcuMTcgMjcuODUgNDE2LjkzIDEzLjkyIDQxNy4wMCAwLjAwIFoiIC8+DQo8L2c+DQo8ZyBpZD0iI2VjZjBmMWZmIj4NCjxwYXRoIGZpbGw9IiNlY2YwZjEiIG9wYWNpdHk9IjEuMDAiIGQ9IiBNIDMzMy44NCAwLjAwIEwgMzc1LjM0IDAuMDAgQyAzNzUuMzAgMTMuODkgMzc1LjM5IDI3Ljc3IDM3NS4yOSA0MS42NiBDIDM2MS40MiA0MS42OCAzNDcuNTUgNDEuNjQgMzMzLjY3IDQxLjY4IEMgMzMzLjc4IDI3Ljc5IDMzMy40MyAxMy44OSAzMzMuODQgMC4wMCBaIiAvPg0KPHBhdGggZmlsbD0iI2VjZjBmMSIgb3BhY2l0eT0iMS4wMCIgZD0iIE0gMzMzLjY4IDgzLjMyIEMgMzQ2LjExIDgzLjMzIDM1OC41NCA4My4zOSAzNzAuOTcgODMuMjcgQyAzNzIuMzcgODMuMzEgMzczLjgzIDgzLjI3IDM3NS4xOSA4My43NSBDIDM3NS41NiA5Ny40OCAzNzUuMjIgMTExLjI0IDM3NS4zMiAxMjQuOTkgQyAzNjEuNDQgMTI1LjAyIDM0Ny41NiAxMjQuOTkgMzMzLjY3IDEyNS4wMCBDIDMzMy42NyAxMTEuMTEgMzMzLjY2IDk3LjIxIDMzMy42OCA4My4zMiBaIiAvPg0KPHBhdGggZmlsbD0iI2VjZjBmMSIgb3BhY2l0eT0iMS4wMCIgZD0iIE0gMzMzLjY3IDE2Ni42NyBDIDM0Ny41NSAxNjYuNjcgMzYxLjQzIDE2Ni42NCAzNzUuMzEgMTY2LjY5IEMgMzc1LjM1IDE4MC41NiAzNzUuMzUgMTk0LjQ0IDM3NS4zMSAyMDguMzEgQyAzNjEuNDMgMjA4LjM2IDM0Ny41NSAyMDguMzMgMzMzLjY3IDIwOC4zMyBDIDMzMy42NyAxOTQuNDQgMzMzLjY3IDE4MC41NiAzMzMuNjcgMTY2LjY3IFoiIC8+DQo8cGF0aCBmaWxsPSIjZWNmMGYxIiBvcGFjaXR5PSIxLjAwIiBkPSIgTSAzMzMuNjggMjUwLjAwIEMgMzQ3LjU2IDI1MC4wMSAzNjEuNDQgMjQ5Ljk5IDM3NS4zMiAyNTAuMDEgQyAzNzUuMjcgMjYzLjkwIDM3NS40OCAyNzcuODAgMzc1LjIxIDI5MS42OSBDIDM2MS4zNiAyOTEuNjUgMzQ3LjUyIDI5MS42NiAzMzMuNjcgMjkxLjY4IEMgMzMzLjY3IDI3Ny43OSAzMzMuNjYgMjYzLjg5IDMzMy42OCAyNTAuMDAgWiIgLz4NCjxwYXRoIGZpbGw9IiNlY2YwZjEiIG9wYWNpdHk9IjEuMDAiIGQ9IiBNIDMzMy42OCAzMzMuMzIgQyAzNDcuNTcgMzMzLjQyIDM2MS40NiAzMzMuMTggMzc1LjM2IDMzMy40NSBDIDM3NS4zMCAzNDcuMjkgMzc1LjM2IDM2MS4xNCAzNzUuMzIgMzc0Ljk5IEMgMzYxLjQ0IDM3NS4wMiAzNDcuNTYgMzc0Ljk5IDMzMy42NyAzNzUuMDAgQyAzMzMuNjcgMzYxLjEwIDMzMy42NiAzNDcuMjEgMzMzLjY4IDMzMy4zMiBaIiAvPg0KPHBhdGggZmlsbD0iI2VjZjBmMSIgb3BhY2l0eT0iMS4wMCIgZD0iIE0gMzMzLjYzIDQxNi42OCBDIDM0Ny41MiA0MTYuNjcgMzYxLjQyIDQxNi42NCAzNzUuMzEgNDE2LjY5IEMgMzc1LjM2IDQzMC41MSAzNzUuMzMgNDQ0LjM0IDM3NS4zMiA0NTguMTYgQyAzNzguMzUgNDU4LjQwIDM4MS4zOSA0NTguNjIgMzg0LjQzIDQ1OC44OCBDIDQwMS40NyA0NjAuNDUgNDE3Ljc2IDQ2Ny45NSA0MzAuNTYgNDc5LjIyIEMgNDQ4LjA2IDQ5NC42NCA0NTguNzMgNTE3LjYxIDQ1OC42NyA1NDAuOTkgQyA0NTguNjYgNTkwLjAwIDQ1OC42NiA2MzkuMDIgNDU4LjY2IDY4OC4wNCBDIDQ1OC42MCA2OTcuODYgNDU4LjkyIDcwNy42OSA0NTguMjIgNzE3LjUwIEMgNDU1LjE2IDc0Ny45NiA0MzMuNzYgNzc1LjUyIDQwNS4xMiA3ODYuMjMgQyAzODMuODQgNzk0LjQ0IDM1OS4xNSA3OTMuMzggMzM4LjcwIDc4My4yMyBDIDMxNC4wNCA3NzEuMzAgMjk1Ljk2IDc0Ni41MyAyOTIuNzMgNzE5LjI2IEMgMjkyLjUwIDcxNS41MiAyOTIuMDcgNzExLjgwIDI5Mi4wMCA3MDguMDYgQyAyOTEuOTkgNjUyLjcxIDI5Mi4wMiA1OTcuMzUgMjkxLjk4IDU0Mi4wMCBDIDI5MS42NiA1MTguMjggMzAyLjM0IDQ5NC44MyAzMjAuMTMgNDc5LjIwIEMgMzM0LjI1IDQ2Ni42NSAzNTIuNzIgNDU5LjE0IDM3MS42MCA0NTguMzUgQyAzNTEuMTMgNDU2LjA0IDMzMy43MiA0MzcuNDIgMzMzLjYzIDQxNi42OCBNIDM1MS44MCA1MDYuODIgQyAzNDIuMTIgNTEyLjk5IDMzNS42OCA1MjMuNjMgMzM0LjE4IDUzNC45NSBDIDMzMi42NyA1NDQuMDUgMzM0LjY5IDU1My40MSAzMzkuMTMgNTYxLjQyIEMgMzQ2LjcwIDU3NC4yNiAzNjAuODIgNTgzLjcwIDM3Ni4wMyA1ODMuMjcgQyAzOTAuNzkgNTgzLjE0IDQwNC4yMyA1NzMuODAgNDExLjU5IDU2MS4zNSBDIDQxNy4xMSA1NTEuNTUgNDE4LjQ4IDUzOS42NCA0MTUuMTcgNTI4Ljg5IEMgNDEyLjI1IDUxOC41NSA0MDQuOTAgNTA5LjU4IDM5NS4zMCA1MDQuNzUgQyAzODEuNzggNDk3Ljg0IDM2NC41NiA0OTguNTEgMzUxLjgwIDUwNi44MiBNIDM0Ny44NCA2MzQuNzYgQyAzMzguNjkgNjQyLjM1IDMzMy43NCA2NTQuMjQgMzMzLjcxIDY2Ni4wMiBDIDMzMy42NiA2NzcuNTcgMzMzLjU3IDY4OS4xMyAzMzMuNzYgNzAwLjY4IEMgMzMyLjM1IDcxMy4wOCAzMzYuMDUgNzI2LjE0IDM0NC41NCA3MzUuNDUgQyAzNTIuNTggNzQ0LjY0IDM2NC42OCA3NTAuNTcgMzc3LjAzIDc0OS45MCBDIDM5Mi4wMCA3NDkuMzEgNDA1LjY0IDczOS40NSA0MTIuMzkgNzI2LjMyIEMgNDE2LjI3IDcxOS4wNiA0MTcuNTUgNzEwLjY1IDQxNi45MSA3MDIuNTEgQyA0MTcuMTEgNjkzLjAzIDQxNi45NCA2ODMuNTYgNDE3LjAxIDY3NC4wOSBDIDQxNi45NyA2NjguNzIgNDE3LjIwIDY2My4zMCA0MTYuMTYgNjU4LjAxIEMgNDE0LjYzIDY0OS42MCA0MTAuNDAgNjQxLjY2IDQwNC4wOCA2MzUuODYgQyAzODkuMDAgNjIxLjgzIDM2My40OSA2MjEuNDIgMzQ3Ljg0IDYzNC43NiBaIiAvPg0KPC9nPg0KPGcgaWQ9IiM5NWE1YTZmZiI+DQo8cGF0aCBmaWxsPSIjOTVhNWE2IiBvcGFjaXR5PSIxLjAwIiBkPSIgTSAzNzUuMzQgMC4wMCBMIDQxNy4wMCAwLjAwIEMgNDE2LjkzIDEzLjkyIDQxNy4xNyAyNy44NSA0MTYuODggNDEuNzYgQyA0MDMuMDMgNDIuMDEgMzg5LjE3IDQxLjM1IDM3NS4zNCA0Mi4wOCBDIDM3NS4yOCA1NS43MSAzNzUuMjYgNjkuMzQgMzc1LjM1IDgyLjk3IEMgMzg5LjE3IDgzLjc3IDQwMy4wMiA4My4wNCA0MTYuODUgODMuMzMgQyA0MTcuMTYgOTcuMjEgNDE2Ljk5IDExMS4xMSA0MTYuOTQgMTI0Ljk5IEMgNDAzLjA5IDEyNS4zMyAzODkuMjIgMTI0Ljc3IDM3NS4zNyAxMjUuMjcgQyAzNzUuMzAgMTM5LjA0IDM3NS4xNSAxNTIuODEgMzc1LjQ1IDE2Ni41OCBDIDM4OS4yNyAxNjYuOTcgNDAzLjExIDE2Ni40MCA0MTYuOTMgMTY2Ljg1IEMgNDE3LjA0IDE4MC42NSA0MTcuMDYgMTk0LjQ1IDQxNi45MyAyMDguMjUgQyA0MDMuMTAgMjA4LjY3IDM4OS4yNiAyMDguMTIgMzc1LjQzIDIwOC41MCBDIDM3NS4xOCAyMjIuMjggMzc1LjMxIDIzNi4wNiAzNzUuMzYgMjQ5LjgzIEMgMzg5LjIxIDI1MC4yOCA0MDMuMDcgMjQ5Ljc5IDQxNi45MiAyNTAuMDUgQyA0MTcuMDYgMjYzLjk1IDQxNy4wOSAyNzcuODUgNDE2LjkwIDI5MS43NSBDIDQwMy4wNCAyOTIuMDkgMzg5LjE1IDI5MS4yMSAzNzUuMzIgMjkyLjE3IEMgMzc1LjQ0IDMwNS44NSAzNzQuOTYgMzE5LjU1IDM3NS41NyAzMzMuMjIgQyAzODkuMzQgMzMzLjQ3IDQwMy4xMSAzMzMuMTkgNDE2Ljg4IDMzMy4zMiBDIDQxNy4xMiAzNDcuMjEgNDE3LjA0IDM2MS4xMCA0MTYuOTEgMzc0Ljk5IEMgNDAzLjA3IDM3NS4zNSAzODkuMjMgMzc0Ljc0IDM3NS40MCAzNzUuMjYgQyAzNzUuMjUgMzg5LjAyIDM3NS4yMCA0MDIuNzkgMzc1LjQzIDQxNi41NSBDIDM4OS4zMiA0MTYuOTggNDAzLjIzIDQxNi40MSA0MTcuMTMgNDE2LjgzIEMgNDE2LjgxIDQzMC4yNSA0MDkuNTQgNDQzLjAyIDM5OC42NCA0NTAuNzEgQyAzOTQuNDQgNDUzLjgyIDM4OS41MSA0NTUuNjkgMzg0LjYxIDQ1Ny4zOSBDIDM4NC41NiA0NTcuNzYgMzg0LjQ3IDQ1OC41MSAzODQuNDMgNDU4Ljg4IEMgMzgxLjM5IDQ1OC42MiAzNzguMzUgNDU4LjQwIDM3NS4zMiA0NTguMTYgQyAzNzUuMzMgNDQ0LjM0IDM3NS4zNiA0MzAuNTEgMzc1LjMxIDQxNi42OSBDIDM2MS40MiA0MTYuNjQgMzQ3LjUyIDQxNi42NyAzMzMuNjMgNDE2LjY4IEMgMzMzLjczIDQwMi43OSAzMzMuNzAgMzg4Ljg5IDMzMy42NyAzNzUuMDAgQyAzNDcuNTYgMzc0Ljk5IDM2MS40NCAzNzUuMDIgMzc1LjMyIDM3NC45OSBDIDM3NS4zNiAzNjEuMTQgMzc1LjMwIDM0Ny4yOSAzNzUuMzYgMzMzLjQ1IEMgMzYxLjQ2IDMzMy4xOCAzNDcuNTcgMzMzLjQyIDMzMy42OCAzMzMuMzIgQyAzMzMuNzEgMzE5LjQ0IDMzMy43MCAzMDUuNTYgMzMzLjY3IDI5MS42OCBDIDM0Ny41MiAyOTEuNjYgMzYxLjM2IDI5MS42NSAzNzUuMjEgMjkxLjY5IEMgMzc1LjQ4IDI3Ny44MCAzNzUuMjcgMjYzLjkwIDM3NS4zMiAyNTAuMDEgQyAzNjEuNDQgMjQ5Ljk5IDM0Ny41NiAyNTAuMDEgMzMzLjY4IDI1MC4wMCBDIDMzMy43MCAyMzYuMTEgMzMzLjcxIDIyMi4yMiAzMzMuNjcgMjA4LjMzIEMgMzQ3LjU1IDIwOC4zMyAzNjEuNDMgMjA4LjM2IDM3NS4zMSAyMDguMzEgQyAzNzUuMzUgMTk0LjQ0IDM3NS4zNSAxODAuNTYgMzc1LjMxIDE2Ni42OSBDIDM2MS40MyAxNjYuNjQgMzQ3LjU1IDE2Ni42NyAzMzMuNjcgMTY2LjY3IEMgMzMzLjcxIDE1Mi43OCAzMzMuNzAgMTM4Ljg5IDMzMy42NyAxMjUuMDAgQyAzNDcuNTYgMTI0Ljk5IDM2MS40NCAxMjUuMDIgMzc1LjMyIDEyNC45OSBDIDM3NS4yMiAxMTEuMjQgMzc1LjU2IDk3LjQ4IDM3NS4xOSA4My43NSBDIDM3My44MyA4My4yNyAzNzIuMzcgODMuMzEgMzcwLjk3IDgzLjI3IEMgMzU4LjU0IDgzLjM5IDM0Ni4xMSA4My4zMyAzMzMuNjggODMuMzIgQyAzMzMuNzIgNjkuNDQgMzMzLjY5IDU1LjU2IDMzMy42NyA0MS42OCBDIDM0Ny41NSA0MS42NCAzNjEuNDIgNDEuNjggMzc1LjI5IDQxLjY2IEMgMzc1LjM5IDI3Ljc3IDM3NS4zMCAxMy44OSAzNzUuMzQgMC4wMCBaIiAvPg0KPHBhdGggZmlsbD0iIzk1YTVhNiIgb3BhY2l0eT0iMS4wMCIgZD0iIE0gNTAwLjI2IDAuMDAgTCA1MDAuMzEgMC4wMCBDIDU4My43MCA4My4yMCA2NjYuODggMTY2LjYzIDc1MC4yNyAyNDkuODMgQyA2OTUuNTIgMjUwLjIzIDY0MC43NiAyNDkuODYgNTg2LjAwIDI1MC4wMiBDIDU2Ni4xNCAyNTAuNjIgNTQ2LjE4IDI0My44NCA1MzAuODggMjMxLjE3IEMgNTE1LjgyIDIxOC44OSA1MDUuMjUgMjAxLjIyIDUwMS43MiAxODIuMTAgQyA0OTkuNzggMTcyLjUyIDUwMC40MiAxNjIuNzAgNTAwLjMzIDE1My4wMCBDIDUwMC4yOCAxMDIuMDAgNTAwLjQzIDUxLjAwIDUwMC4yNiAwLjAwIFoiIC8+DQo8cGF0aCBmaWxsPSIjOTVhNWE2IiBvcGFjaXR5PSIxLjAwIiBkPSIgTSAzNTEuODAgNTA2LjgyIEMgMzY0LjU2IDQ5OC41MSAzODEuNzggNDk3Ljg0IDM5NS4zMCA1MDQuNzUgQyA0MDQuOTAgNTA5LjU4IDQxMi4yNSA1MTguNTUgNDE1LjE3IDUyOC44OSBDIDQxOC40OCA1MzkuNjQgNDE3LjExIDU1MS41NSA0MTEuNTkgNTYxLjM1IEMgNDA2Ljc1IDU1NC41MiA0MDAuNjIgNTQ4LjM2IDM5Mi43MyA1NDUuMjEgQyAzNzkuODAgNTM5LjcxIDM2NC4xNSA1NDAuNjYgMzUyLjIyIDU0OC4yMSBDIDM0Ni44MyA1NTEuNDYgMzQyLjc0IDU1Ni4zNSAzMzkuMTMgNTYxLjQyIEMgMzM0LjY5IDU1My40MSAzMzIuNjcgNTQ0LjA1IDMzNC4xOCA1MzQuOTUgQyAzMzUuNjggNTIzLjYzIDM0Mi4xMiA1MTIuOTkgMzUxLjgwIDUwNi44MiBaIiAvPg0KPHBhdGggZmlsbD0iIzk1YTVhNiIgb3BhY2l0eT0iMS4wMCIgZD0iIE0gMzQ3Ljg0IDYzNC43NiBDIDM2My40OSA2MjEuNDIgMzg5LjAwIDYyMS44MyA0MDQuMDggNjM1Ljg2IEMgNDEwLjQwIDY0MS42NiA0MTQuNjMgNjQ5LjYwIDQxNi4xNiA2NTguMDEgQyA0MTcuMjAgNjYzLjMwIDQxNi45NyA2NjguNzIgNDE3LjAxIDY3NC4wOSBDIDQxNi45NCA2ODMuNTYgNDE3LjExIDY5My4wMyA0MTYuOTEgNzAyLjUxIEMgNDE1LjA5IDY5Mi4zMCA0MTAuNDMgNjgyLjI3IDQwMi4wOCA2NzUuODEgQyAzODUuNDYgNjYyLjMyIDM1OC40NCA2NjMuOTIgMzQ0LjEwIDY4MC4wNCBDIDMzOC42NiA2ODUuNzMgMzM2LjA2IDY5My4zMSAzMzMuNzYgNzAwLjY4IEMgMzMzLjU3IDY4OS4xMyAzMzMuNjYgNjc3LjU3IDMzMy43MSA2NjYuMDIgQyAzMzMuNzQgNjU0LjI0IDMzOC42OSA2NDIuMzUgMzQ3Ljg0IDYzNC43NiBaIiAvPg0KPHBhdGggZmlsbD0iIzk1YTVhNiIgb3BhY2l0eT0iMS4wMCIgZD0iIE0gNDU4LjIyIDcxNy41MCBDIDQ1OS4wOSA3MjYuOTcgNDU4LjUwIDczNi40OCA0NTguNjkgNzQ1Ljk4IEMgNDU4Ljg1IDc1NC4zOCA0NTguMjUgNzYyLjg1IDQ1Ni4wNCA3NzAuOTkgQyA0NTAuNDAgNzkzLjAyIDQzNS4yMyA4MTIuMzQgNDE1LjI2IDgyMy4xOSBDIDM5NS45NCA4MzMuODkgMzcyLjI4IDgzNi4yNSAzNTEuMTcgODI5LjgxIEMgMzIzLjM5IDgyMS42MyAzMDAuOTQgNzk3LjkwIDI5NC4zMSA3NjkuNzEgQyAyOTAuOTYgNzU3LjA5IDI5Mi4yOSA3NDMuOTUgMjkxLjk1IDczMS4wNyBDIDI5MS43OCA3MjcuMTEgMjkyLjIwIDcyMy4xNyAyOTIuNzMgNzE5LjI2IEMgMjk1Ljk2IDc0Ni41MyAzMTQuMDQgNzcxLjMwIDMzOC43MCA3ODMuMjMgQyAzNTkuMTUgNzkzLjM4IDM4My44NCA3OTQuNDQgNDA1LjEyIDc4Ni4yMyBDIDQzMy43NiA3NzUuNTIgNDU1LjE2IDc0Ny45NiA0NTguMjIgNzE3LjUwIFoiIC8+DQo8cGF0aCBmaWxsPSIjOTVhNWE2IiBvcGFjaXR5PSIxLjAwIiBkPSIgTSA3NDMuODQgODY1LjcyIEMgNzQ3Ljg3IDg1Ni45NCA3NDkuMDIgODQ3LjMxIDc1MS4wMCA4MzcuOTYgTCA3NTEuMDAgODc4LjE3IEMgNzQ5LjQ1IDg4Ni42OCA3NDguNTQgODk1LjM5IDc0NS4zNiA5MDMuNTEgQyA3MzUuOTIgOTI5LjkxIDcxMi4zMyA5NTAuNjQgNjg0Ljg5IDk1Ni40NSBDIDY4MC4wMyA5NTcuNDUgNjc1LjA5IDk1OC4wMSA2NzAuMjMgOTU5LjAwIEwgODAuNjIgOTU5LjAwIEMgNzIuMjEgOTU3LjQ5IDYzLjYwIDk1Ni42MCA1NS41NiA5NTMuNTEgQyAzOC45MiA5NDcuNTcgMjQuMzEgOTM2LjE1IDE0LjQ3IDkyMS40NyBDIDUuMjUgOTA3Ljg1IDAuMjAgODkxLjQzIDAuMzIgODc0Ljk2IEMgMC4zOCA4NjUuNjggMC4yMiA4NTYuNDAgMC40NCA4NDcuMTMgQyAzLjI3IDg1My40NSA0LjIzIDg2MC40MiA3LjI0IDg2Ni42OCBDIDE3LjI4IDg4OS45MCAzOC40MSA5MDcuOTUgNjIuOTUgOTE0LjEyIEMgNzcuMzMgOTE4LjAyIDkyLjMyIDkxNi4zMCAxMDcuMDIgOTE2LjY3IEMgMjg0LjY5IDkxNi42NyA0NjIuMzUgOTE2LjY3IDY0MC4wMiA5MTYuNjcgQyA2NTEuMDMgOTE2LjU1IDY2Mi4wNCA5MTYuOTIgNjczLjA0IDkxNi40OCBDIDcwMy43MiA5MTQuNjYgNzMyLjEzIDg5NC4wNyA3NDMuODQgODY1LjcyIFoiIC8+DQo8L2c+DQo8L3N2Zz4NCg==');
    }";
    echo '</style>';
}

if (isset($_GET['go']) && $_GET['go']){
    backup_action($params);
} else if (isset($_GET['restoredb']) && $params['restore_db']) {
    restore_action($params);
} else {
    start_action($params);
}


?>