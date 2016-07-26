<?php

namespace Phser;

use Phser\Config\ParseIni;

class Phser
{
    private $_config;

    public function __construct()
    {
        //确保在连接客户端时不会超时
        set_time_limit(0);

        $this->_config = (new ParseIni('./config/config.ini'))->parse_ini_file();
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

        //阻塞模式
        if (!socket_set_block($sock)) {
            throw new \RuntimeException("socket_set_block err:".socket_strerror(socket_last_error()));
        }

        //绑定到socket端口
        if (!socket_bind($sock, $this->_config['server']['address'], $this->_config['server']['port'])) {
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
        //读取客户端数据
        //socket_read函数会一直读取客户端数据,直到遇见\n,\t或者\0字符.PHP脚本把这写字符看做是输入的结束符.
        $buf = socket_read($connfd, 8192);


        //数据传送 向客户端写入返回结果
        $msg = "HTTP/1.1 200 OK\r\n";
        $msg .= "Server: lee tinyweb\r\n";
        $msg .= "Content-Type: text/html\r\n";
        $msg .= "\r\n";
        $msg .= "<HTML><HEAD><TITLE>Method Not Implemented\r\n";
        $msg .= "</TITLE></HEAD>\r\n";
        $msg .= "<BODY><P>HTTP request method not supported.\r\n";

        $msg .= $buf."\r\n";

        $msg .= "</BODY></HTML>\r\n";

        if (socket_write($connfd, $msg, strlen($msg))) {
            throw new \RuntimeException("socket_write err:".socket_strerror(socket_last_error()));
        }

        socket_close($connfd);
    }
}


