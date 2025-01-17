<?php

namespace Majestic\Litecoin;

use GuzzleHttp\Client as GuzzleHttp;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;

class Client
{
    /**
     * Http Client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client = null;

    /**
     * URL path.
     *
     * @var string
     */
    protected $path = '/';

    /**
     * JSON-RPC Id.
     *
     * @var int
     */
    protected $rpcId = 0;

    /**
     * Constructs new client.
     *
     * @param mixed $config
     * @param array $extras
     * @return void
     */
    public function __construct($config = [], $extras = [])
    {
        // init defaults
        $config = $this->defaultConfig($this->parseUrl($config));

        $handlerStack = HandlerStack::create();
        $handlerStack->push(
            Middleware::mapResponse(function (ResponseInterface $response) {
                return LitecoindResponse::createFrom($response);
            }),
            'json_response'
        );

        // construct client
        $this->client = new GuzzleHttp(array_merge([
            'base_uri'    => "${config['scheme']}://${config['host']}:${config['port']}",
            'auth'        => [
                $config['user'],
                $config['pass'],
            ],
            'verify'      => isset($config['ca']) && is_file($config['ca']) ?
                $config['ca'] : true,
            'handler'     => $handlerStack,
        ], $extras));
    }

    /**
     * Gets http client config.
     *
     * @param string|null $option
     *
     * @return mixed
     */
    public function getConfig($option = null)
    {
        return (
                isset($this->client) &&
                $this->client instanceof ClientInterface
            ) ? $this->client->getConfig($option) : false;
    }

    /**
     * Gets http client.
     *
     * @return \GuzzleHttp\ClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Sets http client.
     *
     * @param  \GuzzleHttp\ClientInterface
     *
     * @return void
     */
    public function setClient(ClientInterface $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Sets wallet for multi-wallet rpc request.
     *
     * @param string $name
     *
     * @return static
     */
    public function wallet($name)
    {
        $this->path = "/wallet/$name";

        return $this;
    }

    /**
     * Makes request to Litecoin Core.
     *
     * @param string $method
     * @param mixed  $params
     *
     * @return array
     */
    public function request($method, $params = [])
    {
        try {
            $json = [
                'method' => strtolower($method),
                'params' => (array) $params,
                'id'     => $this->rpcId++,
            ];

            $response = $this->client->request('POST', $this->path, ['json' => $json]);

            if ($response->hasError()) {
                // throw exception on error
                throw new Exceptions\LitecoindException($response->error());
            }

            return $response;
        } catch (RequestException $exception) {
            if (
                $exception->hasResponse() &&
                $exception->getResponse()->hasError()
            ) {
                throw new Exceptions\LitecoindException($exception->getResponse()->error());
            }

            throw new Exceptions\ClientException(
                $exception->getMessage(),
                $exception->getCode()
            );
        } catch (Exceptions\LitecoindException $exception) {
            throw $exception;
        }
    }

    /**
     * Makes async request to Litecoin Core.
     *
     * @param string        $method
     * @param mixed         $params
     * @param callable|null $onFullfiled
     * @param callable|null $onRejected
     *
     * @return \GuzzleHttp\Promise\Promise
     */
    public function requestAsync(
        $method,
        $params = [],
        callable $onFullfiled = null,
        callable $onRejected = null)
    {
        $json = [
            'method' => strtolower($method),
            'params' => (array) $params,
            'id'     => $this->rpcId++,
        ];

        $promise = $this->client
            ->requestAsync('POST', $this->path, ['json' => $json]);

        $promise->then(
            function (ResponseInterface $response) use ($onFullfiled) {
                $error = null;
                if ($response->hasError()) {
                    $error = new Exceptions\LitecoindException($response->error());
                }

                if (is_callable($onFullfiled)) {
                    $onFullfiled($error ?: $response);
                }
            },
            function (RequestException $exception) use ($onRejected) {
                if (
                    $exception->hasResponse() &&
                    $exception->getResponse()->hasError()
                ) {
                    $exception = new Exceptions\LitecoindException(
                        $exception->getResponse()->error()
                    );
                }

                if ($exception instanceof RequestException) {
                    $exception = new Exceptions\ClientException(
                        $exception->getMessage(),
                        $exception->getCode()
                    );
                }

                if (is_callable($onRejected)) {
                    $onRejected($exception);
                }
            }
        );

        return $promise;
    }

    /**
     * Makes request to Litecoin Core.
     *
     * @param string $method
     * @param array  $params
     *
     * @return array
     */
    public function __call($method, array $params = [])
    {
        $method = str_ireplace('async', '', $method, $count);
        if ($count > 0) {
            return $this->requestAsync($method, ...$params);
        }

        return $this->request($method, $params);
    }

    /**
     * Set default config values.
     *
     * @param array $config
     *
     * @return array
     */
    protected function defaultConfig(array $config = [])
    {
        $defaults = [
            'scheme' => 'http',
            'host'   => '127.0.0.1',
            'port'   => 9332,
            'user'   => '',
            'pass'   => '',
        ];

        return array_merge($defaults, $config);
    }

    /**
     * Expand URL config into components.
     *
     * @param mixed $config
     *
     * @return array
     */
    protected function parseUrl($config)
    {
        if (is_string($config)) {
            $allowed = ['scheme', 'host', 'port', 'user', 'pass'];

            $parts = (array) parse_url($config);
            $parts = array_intersect_key($parts, array_flip($allowed));

            if (!$parts || empty($parts)) {
                throw new Exceptions\ClientException('Invalid url');
            }

            return $parts;
        }

        return $config;
    }

    /**
     * Converts amount from satoshi to litecoin.
     *
     * @param int $amount
     *
     * @return float
     */
    public static function toBtc($amount)
    {
        return bcdiv((int) $amount, 1e8, 8);
    }

    /**
     * Converts amount from litecoin to satoshi.
     *
     * @param float $amount
     *
     * @return int
     */
    public static function toSatoshi($amount)
    {
        return bcmul(static::toFixed($amount, 8), 1e8);
    }

    /**
     * Brings number to fixed pricision without rounding.
     *
     * @param float $number
     * @param int   $precision
     *
     * @return string
     */
    public static function toFixed($number, $precision = 8)
    {
        $number = $number * pow(10, $precision);

        return bcdiv($number, pow(10, $precision), $precision);
    }
}
