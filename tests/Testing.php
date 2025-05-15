<?php

use Dotenv\Dotenv;

require_once(__DIR__ . "/Model.php");
require_once(__DIR__ . "/User.php");
require_once(__DIR__ . "/UserList.php");
require_once(__DIR__ . "/UserGroup.php");
require_once(__DIR__ . "/UserLog.php");


//load env
Dotenv::createImmutable(__DIR__ . "/..")->safeLoad();




class Testing extends Model
{
    public function getName()
    {
        return "a";
    }
}

class Testing2 extends Model
{
   
}

class Testing3 extends Model
{
    
}
