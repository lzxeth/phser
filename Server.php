<?php

namespace Phweb;

use Phweb\Config\ParseIni;
use Phweb\FastCGI\FastCGI;
use Phweb\Socket\Daemon;
use Phweb\Socket\Socket;

class Server
{
    private $_config;
    private $_sock;

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

        //配置文件
        $parseObj = new ParseIni('./Config/config.ini');
        $this->_config = $parseObj->parseConfig();

        //socket
        $this->_sock = new Socket($this->_config['address'], $this->_config['port']);
    }

    public function createSocket()
    {
        /* 监听 */
        $this->_sock->listen();

        do {
            /* 连接 */
            $this->_sock->accept();

            //处理请求
            $this->acceptRequest();
        } while (true);

        $this->_sock->closeListenFD();
    }

    public function acceptRequest()
    {
        /* 根据请求状态行解析出method,query_string,filename */
        $this->parseQueryStatusLine();
        
        /* 只支持GET和POST方法 */
        if ($this->_method !== self::METHOD_POST && $this->_method !== self::METHOD_GET) {
            return $this->unImplemented();
        }

        /* 解析缓冲区剩余数据,GET就丢弃header头,POST则解析请求体 */
        $this->parseQueryEntity();

        /* 判断是否是cgi请求 */
        if ($this->isCgi()) {
            /* 判断请求的文件是否可执行,cgi请求的文件需要有可执行权限 */
            $file = $this->getDynamicFileName();
            $fileInfo = new \SplFileInfo($file);
            if (!$fileInfo->isFile() || !$fileInfo->isExecutable()) {
                return $this->notFound();
            }

            $fastCgi = new FastCGI();
            $environment = $this->getEnvForFastCGI($file);
            $fastCgiResp = $fastCgi->run($environment, $this->_queryEntity);

            /* resp data */
            $this->respData($fastCgiResp);
        } else {
            /* 判断请求的文件是否可读 */
            $file = $this->getStaticFileName();
            $fileInfo = new \SplFileInfo($file);
            if (!$fileInfo->isFile() || !$fileInfo->isReadable()) {
                return $this->notFound();
            }

            $this->cat($file);
        }

        $this->_sock->closeConnectFD();
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
    public function parseQueryStatusLine()
    {
        $line = $this->_sock->readLine();

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

    public function parseQueryEntity()
    {
        if ($this->_method == self::METHOD_GET) {
            do {
                $line = $this->_sock->readLine();
            } while (!empty($line)); // \r\n返回空
        } else {
            do {
                $line = $this->_sock->readLine();
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
            $this->_queryEntity = $this->_sock->read($this->_contentLength);
        }
    }

    /**
     * fastcgi请求需要的环境变量
     *
     * @param $file
     * @return array
     */
    public function getEnvForFastCGI($file)
    {
        return array(
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
    }

    public function unImplemented()
    {
        //读取全部数据
        $this->_sock->read(8192);

        $response = "HTTP/1.1 501 Method Not Implemented\r\n";
        $response .= self::RESP_SERVER;
        $response .= "Content-Type: text/html\r\n";
        $response .= "\r\n";
        $response .= "<HTML><HEAD><TITLE>Method Not Implemented\r\n</TITLE></HEAD>\r\n";
        $response .= "<BODY><P>HTTP request method not supported.\r\n</P></BODY></HTML>\r\n";

        $this->_sock->write($response);
        $this->_sock->closeConnectFD();
    }

    public function respData($resp)
    {
        $this->headers();
        $this->_sock->write($resp);
    }

    public function cat($file)
    {
        $this->headers();

        $fileObj = new \SplFileObject($file, "r");
        while (!$fileObj->eof()) {
            $line = $fileObj->fgets();
            $this->_sock->write($line);
        }
    }

    public function headers()
    {
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= self::RESP_SERVER;
        $response .= $this->isCgi() ? self::RESP_CGI_CONTENT_TYPE : self::RESP_CONTENT_TYPE;
        $response .= "\r\n";

        $this->_sock->write($response);
    }

    public function notFound()
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

        $this->_sock->write($response);
        $this->_sock->closeConnectFD();
    }
}

try{
    $server = new Server();
    $d = new Daemon('nobody', '/data0/www/phweb/daemon.log');
    $d->daemonize();
    $d->setJobs([$server, 'createSocket']);
    $d->start(2);
}catch (\Exception $e){
    echo $e->getFile().':'.$e->getLine().':'.$e->getMessage();
}




