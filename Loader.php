<?php

namespace Phweb;

class Loader
{
    private static $_classPath;

    public static function loadClass($classname)
    {
        $classpath = self::getClassPath();

        if (isset($classpath[$classname])) {
            include($classpath[$classname]);
        }
    }

    protected static function getClassPath()
    {
        if (!empty(self::$_classPath)) {
            return self::$_classPath;
        }

        return self::$_classPath = self::getClassMapDef();
    }

    protected static function getClassMapDef()
    {
        return array(
            'Phweb\Config\ParseIni' => '/data0/www/phweb/Config/ParseIni.php',
            'Phweb\FastCGI\Client'  => '/data0/www/phweb/FastCGI/Client.php',
            'Phweb\FastCGI\FastCGI' => '/data0/www/phweb/FastCGI/FastCGI.php',
        );
    }
}

spl_autoload_register(array("\\Phweb\\Loader", "loadClass"));
?>