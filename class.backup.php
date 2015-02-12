<?php

class Backup
{
    private $api_key;
    private $username;
    private $folder;
    
    public function __construct($path_to_folder = '')
    {
        $this->setFolder($path_to_folder);

        $this->username = USERNAME;
        
        // api key
        if (isset($_GET['key']))
            $this->api_key = $_GET['key'];
        else
            exit(json_encode(array('status'=>'error', 'message'=>'Missing key parameter')));
        
        // authentication ?
        $id = $_GET['id'];
        $token = md5($this->username . date("YM"));
        if ($token != $id)
            exit(json_encode(array('status'=>'error', 'message'=>'Token mismatch error')));
        
        // uploaded file name
        if (isset($_GET['filename']))
            $this->filename = $_GET['filename'];
        else
            $this->filename = DOMAIN . '/backup_' . date("Y-m-d-H-i-s") . '.zip';
    }
    
    public function setFolder($path_to_folder)
    {
        $this->folder = $path_to_folder;
        if ( ! $this->checkFolderPath())
            exit(json_encode(array('status'=>'error', 'message'=>'Folder does not exist')));
    }
    
    public function backup() 
    {
        // check folder size
        $size = number_format(($this->getFolderSize($this->folder)/1024/1024));
        if ($size > 4608) // ~ 5 GB
            exit(json_encode(array('status'=>'error', 'message'=>'Backup failed. Max size of 5GB exceded.')));
        
        // create db backup
        $this->createDatabaseBackup();
        
        // create zip file of 'files' directory and db backup
        $this->createZipFile();
        
        // upload to rackspace
        $this->uploadFile();
        
        // delete temp files
        $this->deleteTempFiles();
    }
    
    public function test()
    {
        // folder exists
        if ( ! $this->checkFolderPath($this->folder))
            exit(json_encode(array('status'=>'error', 'message'=>'Invalid path to folder')));

        // folder size
        $size = number_format(($this->getFolderSize($this->folder)/1024/1024));
        if ($size > 4608) // ~ 5 GB
            exit(json_encode(array('status'=>'error', 'message'=>'Backup failed. Max size of 5GB exceded.')));

        // tmp folder is writable
        if ( ! is_writable('./tmp'))
            exit(json_encode(array('status'=>'error', 'message' => 'Temp folder isn\'t writable. Check permissions')));
            
        // db credentials
        $link = mysql_connect(DB_HOST, DB_USER, DB_PASS);
        if ( ! $link)
            exit(json_encode(array('status'=>'error', 'message' => 'Could not connect to database')));
        $db = mysql_select_db(DB_NAME, $link);
        if ( ! $db)
            exit(json_encode(array('status'=>'error', 'message' => 'Could not connect to database')));
            
        // connection to rackspace
        require_once('./lib/php-opencloud.php');
        try
        {
            $connection = new \OpenCloud\Rackspace(AUTH_URL, array(
                'username' => $this->username,
                'apiKey' => $this->api_key
            ));
            $object_store = $connection->ObjectStore('cloudFiles', DATA_CENTER);
            $container = $object_store->Container('client backups');
        
        }
        catch (HttpUnauthorizedError $e)
        {
            exit(json_encode(array('status' => 'error', 'message' => 'Could not connect to Cloud Files')));
        }
        
    }
    
    private function checkFolderPath()
    {
        return is_dir($this->folder);
    }
    
    private function getFolderSize($folder)
    {
        if (!($dh = opendir($folder))) return 0; 

        $total = 0; 
        while (($file = readdir($dh)) !== false) 
        { 
            if ($file != '.' && $file != '..') 
            { 
                $file = $folder . '/' . $file; 
                if (is_dir($file) && is_readable($file) && !is_link($file)) 
                    $total += $this->getFolderSize($file); 
                else 
                    $total += filesize($file); 
            } 
        } 
        closedir($dh); 
        return $total; 
    }
    
    private function createDatabaseBackup()
    {
        // check credentials
        $link = mysql_connect(DB_HOST, DB_USER, DB_PASS);
        if ( ! $link)
            exit(json_encode(array('status'=> 'error', 'message' => 'Backup failed. Could not connect ta database server')));
        $db = mysql_select_db(DB_NAME, $link);
        if ( ! $db)
            exit(json_encode(array('status'=>'error', 'message' => 'Backup failed. Could not find database.')));
                        
        // i have no idea what this shit does, but it seems to work
        // https://github.com/jeremehancock/zipit/blob/master/zipit-zip-db-auto.php
        $cmd = "mysqldump -h " . DB_HOST . 
               " -u " . DB_USER . 
               " --password='" . DB_PASS . "' " . DB_NAME . 
               " > ./tmp/" . DB_NAME . ".sql";
        $pipe = popen($cmd, 'r');
        stream_set_blocking($pipe, false);
        while (!feof($pipe))
            fread($pipe, 1024);
        pclose($pipe);
    }
    
    private function createZipFile()
    {
        chdir("./tmp");
        shell_exec("zip -9pr ./backup.zip ./" . DB_NAME . ".sql ../" . $this->folder);
        chdir("../");
    }
    
    private function uploadFile()
    {
        require_once('./lib/php-opencloud.php');
        try
        {
            $connection = new \OpenCloud\Rackspace(AUTH_URL, array(
                'username' => $this->username,
                'apiKey' => $this->api_key
            ));
            $object_store = $connection->ObjectStore('cloudFiles', DATA_CENTER);
            $container = $object_store->Container('client backups');
            $file = $container->DataObject();
            $file->Create(array(
                'name' => $this->filename,
                'content_type' => 'application/octet-stream'
            ), './tmp/backup.zip');
        }
        catch (HttpUnauthorizedError $e)
        {
            exit(json_encode(array('status' => 'error', 'message' => 'Backup Failed. Could not connect to Cloud Files')));
        }
    }
    
    private function deleteTempFiles()
    {
        if ( ! ($handle = opendir('./tmp'))) 
            return false;
        while (($file = readdir($handle)) !== false)
        {
            if (substr($file, 0, 1) != '.')
                unlink('./tmp/' . $file);
        }
    }
}