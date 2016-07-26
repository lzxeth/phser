<?php
namespace Phser\Config;

class ParseIni
{
    public function __construct($path) 
    {
        $this->_configPath = $path;
    }

    public function parseConfig()
    {
        if (!$this->_configPath) {
            throw new LogicException('path is null.');
        }

        if (!file_exists($this->_configPath)) {
            throw new InvalidArgumentException('path is invalid.');
        }

        return parse_ini_file($this->_configPath);
    }
}