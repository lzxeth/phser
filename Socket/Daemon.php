<?php

namespace Phweb\Socket;

class Daemon
{
    /**
     * job状态标识,确认是否当前job已完成
     * @var bool
     */
    private $_jobFlag = false;

    /**
     * signal状态标识, 确认是否收到 kill -1 signal
     * @var bool
     */
    private $_signalFlag = false;

    private $_initMD5File;
    
    public function __construct($configFileName)
    {
        if (!file_exists($configFileName)) {
            throw new \RuntimeException('the config file is not exists.');
        }
        $this->_configFileName = $configFileName;
        $this->_initMD5File = md5_file($this->_configFileName);
    }

    public function start()
    {
        declare(ticks = 1);
        // 定期检测配置文件,如果配置文件修改就自动重启
        // 手动优雅重启的话,只要发送 HUP signal



        // 注册信号处理
        pcntl_signal(SIGALRM, array($this, "signal_handler"), true);
        pcntl_signal(SIGHUP, array($this, 'signal_handler'), true);


        while (1) {
            $this->_jobFlag = false;
            print "Worker start running ... n";
            sleep(5);
            print "Worker's task done ... n";
            $this->_flag = true;    //Job status flag
            $this->autoStart($this->_jobFlag);
        }
    }

    public function signal_handler($signal)
    {
        switch ($signal) {
            case SIGQUIT:
                print date('y-m-d H:i:s', time())." Caught Signal : SIGQUIT - No : $signal n";
                exit(0);
                break;
            case SIGSTOP:
                print date('y-m-d H:i:s', time())." Caught Signal : SIGSTOP - No : $signal n";
                break;
            case SIGHUP:
                print date('y-m-d H:i:s', time())." Caught Signal : SIGHUP - No : $signal n";
                if ($this->_flag === true) {
                    AutoStart(true);
                } else {
                    $this->_signalFlag = true;
                }
                break;
            case SIGALRM:
                print date('y-m-d H:i:s', time())." Caught Signal : SIGALRM - No : $signal n";
                //pcntl_exec( '/bin/ls' );
                pcntl_alarm(5);
                break;
            default:
                break;
        }
    }

    public function autoStart($signal = false)
    {
        if ($signal || md5_file($this->_configFileName) != $this->_initMD5File) {
            print "The config file has been changed, we are going to restart. n";

            if (($pid = pcntl_fork()) == -1) {
                print "Fork error n";
            } else if ($pid > 0) {
                print "Parent exit n";
                exit(0);
            } else {
                $this->_initMD5File = md5_file($this->_configFileName);
                print "Child continue to run n";
            }
        }
    }

}