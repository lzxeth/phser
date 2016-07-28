<?php

namespace Phweb\FastCGI;

class FastCGI
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $host;

    /**
     * @param string $host 127.0.0.1:9000 or /var/doRun/php5-fpm.sock
     * @param string $tempDir
     */
    public function __construct($host = null, $tempDir = null)
    {
        // try to guess where it is
        if ($host === null) {
            if (file_exists('/var/doRun/php5-fpm.sock')) {
                $host = '/var/doRun/php5-fpm.sock';
            } else {
                $host = '127.0.0.1:9000';
            }
        }

        $this->host = $host;

        if (false !== strpos($host, ':')) {
            list($host, $port) = explode(':', $host);
            $this->client = new Client($host, $port);
        } else {
            // socket
            $this->client = new Client('unix://' . $host, -1);
        }

        $this->client->setReadWriteTimeout(60 * 1000);
        $this->client->setPersistentSocket(false);
        $this->client->setKeepAlive(true);
    }

    /**
     * {@inheritdoc}
     */
    public function run($environment, $stdin)
    {
        $response = $this->request($environment, $stdin);

        $parts = explode("\r\n\r\n", $response);

        // remove headers
        $header = array_shift($parts);
        $body = implode("\r\n\r\n", $parts);

        return $body;
    }

    protected function request($environment, $stdin)
    {
        //$this->logger->info(sprintf('FastCGI: Dumped code to file: %s', $file));

        try {


            $response = $this->client->request($environment, $stdin);
            //$this->logger->debug(sprintf('FastCGI: Response: %s', json_encode($response)));

            return $response;
        } catch (\Exception $e) {
            throw new \RuntimeException(
                sprintf('FastCGI error: %s (%s)', $e->getMessage(), $this->host),
                $e->getCode(),
                $e
            );
        }
    }
}