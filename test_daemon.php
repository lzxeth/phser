<?php

class Daemon
{
    private $run_pid_dir = "/tmp";
    private $pid_file = "";
    private $terminate = false; //是否中断
    private $workers_count = 0;
    private $workers_max = 8; //最多运行8个进程

    public function __construct($user = 'nobody', $output = "/dev/null")
    {
        $this->user   = $user;//设置运行的用户 默认情况下nobody
        $this->output = $output; //设置输出的地方
        $this->checkPcntl();
    }

    //检查环境是否支持pcntl支持
    public function checkPcntl()
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            declare(ticks = 10);
        }

        if (!function_exists('pcntl_signal')) {
            $message = 'pcntl ext is necessary.';
            $this->_log($message);
            throw new Exception($message);
        }

        /**
         * 第三个参数指定当信号到达时系统调用重启是否可用。
         * (译注：经查资料，此参数意为系统调用被信号打断时，系统调用是否从开始处重新开始，
         * 但根据http://bugs.php.net/bug.php?id=52121，此参数存在bug无效。)
         */
        pcntl_signal(SIGTERM, array(__CLASS__, "signalHandler"), false);
        pcntl_signal(SIGINT, array(__CLASS__, "signalHandler"), false);
        pcntl_signal(SIGQUIT, array(__CLASS__, "signalHandler"), false);
    }

    // daemon化程序
    public function daemonize()
    {
        global $stdin, $stdout, $stderr;
        global $argv;

        set_time_limit(0);

        // 只允许在cli下面运行
        if (php_sapi_name() != "cli") {
            die("only run in command line mode\n");
        }

        // 检查是否已经启动
        $this->pid_file = $this->run_pid_dir."/".__CLASS__."_".substr(basename($argv[0]), 0, -4).".pid";
        $this->checkPidfile();

        umask(0); //把文件掩码清0

        if (pcntl_fork() != 0) { //是父进程，父进程退出
            exit();
        }

        posix_setsid();//设置新会话组长，脱离终端

        if (pcntl_fork() != 0) { //是第一子进程，结束第一子进程
            exit();
        }

        chdir("/"); //改变工作目录

        $this->setUser($this->user) or die("cannot change owner");

        //关闭打开的文件描述符
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $stdin  = fopen($this->output, 'r');
        $stdout = fopen($this->output, 'a');
        $stderr = fopen($this->output, 'a');

        //生成pid文件
        $this->createPidfile();
    }


    /**
     * 检测pidfile是否存在
     *
     * @return bool
     */
    public function checkPidfile()
    {
        if (!file_exists($this->pid_file)) {
            return true;
        }
        $pid = file_get_contents($this->pid_file);
        $pid = intval($pid);

        //posix_kill 0 代表向pid发送空信号，使用这个方法对某个进程的存在性进行检查，而不会真的发送信号。
        if ($pid > 0 && posix_kill($pid, 0)) {
            $this->_log("the daemon process is already started");
        } else {
            $this->_log("the daemon proces end abnormally, please check pidfile ".$this->pid_file);
        }
        exit(1);
    }

    /**
     *创建pid文件
     */
    public function createPidfile()
    {
        if (!is_dir($this->run_pid_dir)) {
            mkdir($this->run_pid_dir);
        }
        $fp = fopen($this->pid_file, 'w') or die("cannot listen pid file");
        fwrite($fp, posix_getpid());
        fclose($fp);
        $this->_log("listen pid file ".$this->pid_file);
    }

    //设置运行的用户
    public function setUser($name)
    {

        $result = false;
        if (empty($name)) {
            return true;
        }
        $user = posix_getpwnam($name);
        if ($user) {
            $uid    = $user['uid'];
            $gid    = $user['gid'];
            $result = posix_setuid($uid);
            posix_setgid($gid);
        }

        return $result;

    }

    public function setRunPidDir($path)
    {
        $this->run_pid_dir = $path;
    }

    //信号处理函数
    public function signalHandler($signo)
    {
        switch ($signo) {
            //用户自定义信号
            case SIGUSR1: //busy
                if ($this->workers_count < $this->workers_max) {
                    $pid = pcntl_fork();
                    if ($pid > 0) {
                        $this->workers_count++;
                    }
                }
                break;
            //子进程结束信号
            case SIGCHLD:
                while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                    $this->workers_count--;
                }
                break;
            //中断进程
            case SIGTERM:
            case SIGHUP:
            case SIGQUIT:
                $this->terminate = true;
                break;
            default:
                return false;
        }
    }

    /**
     * 开始开启进程
     *
     * $count 准备开启的进程数
     */
    public function start($count = 1)
    {
        $this->_log("daemon process is running now");

        //接收子进程退出信号
        pcntl_signal(SIGCHLD, array(__CLASS__, "signalHandler"), false);

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($this->terminate) {
                break;
            }

            if ($this->workers_count < $count) {
                $pid = pcntl_fork();
            }

            if ($pid > 0) {
                $this->workers_count++;
            } else {
                // 这个符号表示恢复系统对信号的默认处理
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGCHLD, SIG_DFL);
                if (!empty($this->jobs)) {
                    call_user_func($this->jobs);
                }
                exit();
            }

            return;
        }

        $this->mainQuit();
        exit(0);
    }


    //整个进程退出
    public function mainQuit()
    {

        if (file_exists($this->pid_file)) {
            unlink($this->pid_file);
            $this->_log("delete pid file ".$this->pid_file);
        }
        $this->_log("daemon process exit now");
        posix_kill(0, SIGKILL);
        exit(0);
    }

    /**
     * 设置任务
     *
     * @param array $jobs
     */
    public function setJobs(array $jobs)
    {
        $this->jobs = $jobs;
    }

    //日志处理
    private function _log($message)
    {
        printf("%s\t%d\t%d\t%s\n", date("c"), posix_getpid(), posix_getppid(), $message);
    }
}

$daemon = new Daemon(true, 'nobody', '/data0/www/phweb/daemon.log');
$daemon->daemonize();
$daemon->start(1);//开启1个子进程工作


