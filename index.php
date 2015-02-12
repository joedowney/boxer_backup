<?php

date_default_timezone_set('America/Indiana/Indianapolis');

include('../admin/includes/settings.php');
include('class.backup.php');

// config options
$path = '../files/';
define('DOMAIN', 'www.example.com');
define('USERNAME', 'smallbox');
define('AUTH_URL', 'https://identity.api.rackspacecloud.com/v2.0/');
define('DATA_CENTER', 'DFW');

$backup = new Backup($path);

if (isset($_GET['test']))
    $backup->test();
else
    $backup->backup();

exit(json_encode(array('status'=>'success', 'message' => ' ')));
