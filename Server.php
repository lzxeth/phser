<?php

namespace Phweb;

use Phweb\Config\ParseIni;
use Phweb\FastCGI\FastCGI;

class Phser
{
    private $_config;

    /* request environment */
    private $_method;
    private $_requestUri;
    private $_queryString;
    private $_filename;
    private $_contentType;
    private $_contentLength;

    /* request stdin */
    private $_queryEntity;

    /* resp Server */
    const RESP_SERVER = "Server: lzx-tiny-httpd/0.1.0\r\n";
    const RESP_CONTENT_TYPE = "Content-Type: text/html\r\n";

    /* cgi请求返回json类型postman才能友好显示 */
    const RESP_CGI_CONTENT_TYPE = "Content-Type: application/json;charset=utf-8\r\n";


    const METHOD_POST = 'POST';
    const METHOD_GET = 'GET';

    public function __construct()
    {
        //自动加载
        include "./Loader.php";

        //确保在连接客户端时不会超时
        set_time_limit(0);

        $parseObj = new ParseIni('./Config/config.ini');

        $this->_config = $parseObj->parseConfig();
    }

    public function createSocket()
    {
        /**
         * 创建一个SOCKET
         * AF_INET=是ipv4 如果用ipv6，则参数为 AF_INET6
         * SOCK_STREAM为socket的tcp类型，如果是UDP则使用SOCK_DGRAM
         */
        if (!($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
            throw new \RuntimeException("socket_create err:".socket_strerror(socket_last_error()));
        }

        ////在修改源码后重启启动总是提示bind: Address already in use,使用tcpreuse解决
        if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
            throw new \RuntimeException("socket_set_option err:".socket_strerror(socket_last_error()));
        }

        //阻塞模式
        if (!socket_set_block($sock)) {
            throw new \RuntimeException("socket_set_block err:".socket_strerror(socket_last_error()));
        }

        //绑定到socket端口
        if (!socket_bind($sock, $this->_config['address'], $this->_config['port'])) {
            throw new \RuntimeException("socket_bind err:".socket_strerror(socket_last_error()));
        }

        //开始监听
        if (!socket_listen($sock, 5)) {
            throw new \RuntimeException("socket_listen err:".socket_strerror(socket_last_error()));
        }

        do {
            // never stop the daemon
            //它接收连接请求并调用一个子连接Socket来处理客户端和服务器间的信息
            if (!($connfd = socket_accept($sock))) {
                throw new \RuntimeException("socket_accept err:".socket_strerror(socket_last_error()));
            }

            //处理请求
            $this->acceptRequest($connfd);
        } while (true);

        socket_close($sock);
    }

    public function acceptRequest($connfd)
    {
        /* 根据请求状态行解析出method,query_string,filename */
        $this->parseQueryStatusLine($connfd);
        
        /* 只支持GET和POST方法 */
        if ($this->_method !== self::METHOD_POST && $this->_method !== self::METHOD_GET) {
            return $this->unImplemented($connfd);
        }

        /* 解析缓冲区剩余数据,GET就丢弃header头,POST则解析请求体 */
        $this->parseQueryEntity($connfd);

        /* 判断是否是cgi请求 */
        if ($this->isCgi()) {
            /* 判断请求的文件是否可执行,cgi请求的文件需要有可执行权限 */
            $file = $this->getDynamicFileName();
            $fileInfo = new \SplFileInfo($file);
            if (!$fileInfo->isFile() || !$fileInfo->isExecutable()) {
                return $this->notFound($connfd);
            }

            $fastCgi = new FastCGI();

            //构造fast-cgi请求
            $environment = array(
                'GATEWAY_INTERFACE' => 'FastCGI/1.0',
                'REQUEST_METHOD'  => $this->_method,
                'SCRIPT_FILENAME' => $file,
                'SERVER_SOFTWARE'   => 'php/fcgiclient',
                'REMOTE_ADDR'       => '127.0.0.1',
                'REMOTE_PORT'       => '9985',
                'SERVER_ADDR'       => '127.0.0.1',
                'SERVER_PORT'       => '80',
                'SERVER_NAME'       => self::RESP_SERVER,
                'SERVER_PROTOCOL'   => 'HTTP/1.1',
                'REQUEST_URI'     => $this->_requestUri,
                'QUERY_STRING'    => $this->_queryString,
                'CONTENT_TYPE'    => $this->_contentType,
                'CONTENT_LENGTH'    => $this->_contentLength,
            );

            $fastCgiResp = $fastCgi->run($environment, $this->_queryEntity);

            /* resp data */
            $this->respData($connfd, $fastCgiResp);
        } else {
            /* 判断请求的文件是否可读 */
            $file = $this->getStaticFileName();
            $fileInfo = new \SplFileInfo($file);
            if (!$fileInfo->isFile() || !$fileInfo->isReadable()) {
                return $this->notFound($connfd);
            }

            $this->cat($connfd, $file);
        }

        socket_close($connfd);
    }

    /**
     * 是否是动态请求
     *
     * @param $method
     * @param $queryString
     * @return bool
     */
    public function isCgi()
    {
        return ($this->_method == self::METHOD_POST || !empty($this->_queryString));
    }

    public function getStaticFileName()
    {
        if (!$this->_filename) {
            throw new \RuntimeException('the filename parse err.');
        }
        return rtrim($this->_config['web_dir'], '/').'/static'.$this->_filename;
    }

    public function getDynamicFileName()
    {
        if (!$this->_filename) {
            throw new \RuntimeException('the filename parse err.');
        }
        return rtrim($this->_config['web_dir'], '/').'/dynamic'.$this->_filename;
    }

    /**
     * 解析请求状态行
     *
     * @param $connfd
     */
    public function parseQueryStatusLine($connfd)
    {
        $line = $this->getLine($connfd);

        $statusLineArr = explode(' ', trim($line));
        if (!is_array($statusLineArr) || count($statusLineArr) !== 3) {
            throw new \RuntimeException('parse request status line err.');
        }

        list($this->_method, $this->_requestUri, $protocal) = $statusLineArr;

        if (strpos($this->_requestUri, '?') !== false) {
            $this->_filename    = strstr($this->_requestUri, '?', true);
            $this->_queryString = trim(strstr($this->_requestUri, '?'), '?');
        }else{
            $this->_filename    = $this->_requestUri;
            $this->_queryString = '';
        }
    }

    public function parseQueryEntity($connfd)
    {
        if ($this->_method == self::METHOD_GET) {
            do {
                $line = $this->getLine($connfd);
            } while (!empty($line)); // \r\n返回空
        } else {
            do {
                $line = $this->getLine($connfd);
                if (strpos($line, 'Content-Length:') !== false) {
                    $this->_contentLength = intval(trim(str_replace('Content-Length:', '', $line)));
                }

                if (strpos($line, 'Content-Type:') !== false) {
                    $this->_contentType = trim(str_replace('Content-Type:', '', $line));
                }
            } while (!empty($line));

            if (empty($this->_contentLength)) {
                throw new \LogicException('POST RQUEST CONTENT-LEHGTH IS NULL.');
            }

            /* 读取消息体 */
            $this->_queryEntity = socket_read($connfd, $this->_contentLength);
        }
    }

    /**
     * 从socket中读取一行数据
     *
     * @param $connfd
     * @return string
     */
    public function getLine($connfd)
    {
        /* PHP_NORMAL_READ碰到\r,\n,\0就停止 */
        $buf = trim(socket_read($connfd, 1024, PHP_NORMAL_READ)); //trim去掉末尾的\r

        /* 读取\n */
        socket_read($connfd, 1);

        return $buf;
    }

    public function unImplemented($connfd)
    {
        //读取全部数据
        socket_read($connfd, 8192);

        $response = "HTTP/1.1 501 Method Not Implemented\r\n";
        $response .= self::RESP_SERVER;
        $response .= "Content-Type: text/html\r\n";
        $response .= "\r\n";
        $response .= "<HTML><HEAD><TITLE>Method Not Implemented\r\n</TITLE></HEAD>\r\n";
        $response .= "<BODY><P>HTTP request method not supported.\r\n</P></BODY></HTML>\r\n";

        if (!socket_write($connfd, $response, strlen($response))) {
            throw new \RuntimeException("socket_write err:".socket_strerror(socket_last_error()));
        }

        socket_close($connfd);
    }

    public function respData($connfd, $resp)
    {
        $this->headers($connfd);

        if (!socket_write($connfd, $resp, strlen($resp))) {
            throw new \RuntimeException("socket_write err:".socket_strerror(socket_last_error()));
        }
    }

    public function cat($connfd, $file)
    {
        $this->headers($connfd);

        $fileObj = new \SplFileObject($file, "r");
        while (!$fileObj->eof()) {
            $line = $fileObj->fgets();
            if (!socket_write($connfd, $line, strlen($line))) {
                throw new \RuntimeException("socket_write err:".socket_strerror(socket_last_error()));
            }
        }
    }

    public function headers($connfd)
    {
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= self::RESP_SERVER;
        $response .= $this->isCgi() ? self::RESP_CGI_CONTENT_TYPE : self::RESP_CONTENT_TYPE;
        $response .= "\r\n";

        if (!socket_write($connfd, $response, strlen($response))) {
            throw new \RuntimeException("socket_write err:".socket_strerror(socket_last_error()));
        }
    }

    public function notFound($connfd)
    {
        $response = "HTTP/1.1 404 NOT FOUND\r\n";
        $response .= self::RESP_SERVER;
        $response .= "Content-Type: text/html\r\n";
        $response .= "\r\n";
        $response .= "<HTML><TITLE>Not Found</TITLE>\r\n";
        $response .= "<BODY><P>The server could not fulfill\r\n";
        $response .= "your request because the resource specified\r\n";
        $response .= "is unavailable or nonexistent.\r\n";
        $response .= "</BODY></HTML>\r\n";

        if (!socket_write($connfd, $response, strlen($response))) {
            throw new \RuntimeException("socket_write err:".socket_strerror(socket_last_error()));
        }

        socket_close($connfd);
    }
}

$d = new Phser();

try{
    $d->createSocket();
}catch (\Exception $e){
    echo $e->getFile().':'.$e->getLine().':'.$e->getMessage();
}




