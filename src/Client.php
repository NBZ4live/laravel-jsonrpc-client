<?php

namespace Nbz4live\JsonRpc\Client;

use Illuminate\Support\Facades\Log;
use Nbz4live\JsonRpc\Client\Exceptions\JsonRpcException;
use Nbz4live\JsonRpc\Client\Transports\JsonRpcTransport;

/**
 * Class Client
 * @package Nbz4live\JsonRpc\Client
 *
 * @method static static get(string $serviceName)
 * @method static static batch()
 * @method static static cache($minutes = -1)
 * @method static static setHeader(string $name, mixed|callable $value)
 * @method static execute()
 * @method static Response call(string $method, array $params)
 */
class Client
{
    const CODE_PARSE_ERROR = -32700;

    const CODE_INVALID_REQUEST = -32600;

    const CODE_METHOD_NOT_FOUND = -32601;

    const CODE_INVALID_PARAMS = -32602;

    const CODE_INTERNAL_ERROR = -32603;

    const CODE_INVALID_PARAMETERS = 6000;

    const CODE_VALIDATION_ERROR = 6001;

    const CODE_UNAUTHORIZED = 7000;

    const CODE_FORBIDDEN = 7001;

    const CODE_EXTERNAL_INTEGRATION_ERROR = 8000;

    const CODE_INTERNAL_INTEGRATION_ERROR = 8001;

    public static $jsonrpc_messages = [
        self::CODE_PARSE_ERROR => 'Ошибка обработки запроса',
        self::CODE_INVALID_REQUEST => 'Неверный запрос',
        self::CODE_METHOD_NOT_FOUND => 'Указанный метод не найден',
        self::CODE_INVALID_PARAMS => 'Неверные параметры',
        self::CODE_INTERNAL_ERROR => 'Внутренняя ошибка',
        self::CODE_INVALID_PARAMETERS => 'Неверные параметры',
        self::CODE_VALIDATION_ERROR => 'Ошибка валидации',
        self::CODE_UNAUTHORIZED => 'Неверный ключ авторизации',
        self::CODE_FORBIDDEN => 'Доступ запрещен',
        self::CODE_EXTERNAL_INTEGRATION_ERROR => 'Ошибка внешних сервисов',
        self::CODE_INTERNAL_INTEGRATION_ERROR => 'Ошибка внутренних сервисов',
    ];

    protected $connectionName = null;

    protected $serviceName = null;

    protected $is_batch = false;

    protected $cache = null;

    /** @var Request[] */
    protected $requests = [];

    /** @var Response[] */
    protected $results = [];

    protected $headers = [];

    private $time = 0;

    /** @var JsonRpcTransport */
    private $transport;

    public function __construct(JsonRpcTransport $transport)
    {
        $this->requests = [];
        $this->results = [];
        $this->transport = $transport;
    }

    public static function __callStatic($method, $params)
    {
        $instance = app(static::class);

        return $instance->$method(...$params);
    }

    public function __call($method, $params)
    {
        if (method_exists($this, '_' . $method)) {
            return $this->{'_' . $method}(...$params);
        } else {
            return $this->_call($method, $params);
        }
    }

    /**
     * Устанавливает имя сервиса для текущего экземпляра клиента
     *
     * @param string $serviceName
     *
     * @return $this
     */
    protected function _get($serviceName)
    {
        $this->serviceName = $serviceName;

        return $this;
    }

    /**
     * Помечает экземпляр клиента как массив вызовов
     * @return $this
     */
    protected function _batch()
    {
        $this->requests = [];
        $this->results = [];
        $this->is_batch = true;

        return $this;
    }

    /**
     * Помечает вызываемый метод кешируемым
     *
     * @param int $time
     *
     * @return $this
     */
    protected function _cache($minutes = -1)
    {
        $this->cache = $minutes;

        return $this;
    }

    /**
     * Sets one request header.
     *
     * @param string $name
     * @param mixed|callable $value
     *
     * @return $this
     */
    protected function _setHeader(string $name, $value)
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Sets multiple request headers.
     *
     * @param array $headers
     *
     * @return $this
     */
    protected function _setHeaders(array $headers)
    {
        foreach ($headers as $name => $value) {
            if (!isset($this->headers[$name]) && null !== $value) {
                $this->_setHeader($name, $value);
            }
        }

        return $this;
    }

    /**
     * Выполняет удаленный вызов (либо добавляет его в массив)
     *
     * @param string $method
     * @param array $params
     *
     * @return Response
     */
    protected function _call($method, $params)
    {
        if (!$this->is_batch) {
            $this->requests = [];
            $this->results = [];
        }

        $request = $this->createRequest($method, $params);
        $this->requests[$request->getId()] = $request;
        $this->results[$request->getId()] = new Response();

        $this->cache = null;

        if (!$this->is_batch) {
            $this->_execute();
        }

        return $this->results[$request->getId()];
    }

    /**
     * Выполняет запрос всех вызовов
     */
    protected function _execute()
    {
        $this->time = microtime(true);

        $connectionName = $this->getConnectionName();
        $serviceName = $this->getServiceName();

        // настройки подключения
        $settings = $this->getConnectionOptions($connectionName);

        $this->_setHeader('Content-type', 'application/json');

        if ($settings['authHeader'] !== null && $settings['key'] !== null) {
            $this->_setHeader($settings['authHeader'], $settings['key']);
        }

        $this->_setHeaders($settings['additionalHeaders']);

        // если не заданы настройки хоста
        if (null === $settings['host']) {
            Log::error('No settings for the connection "' . $connectionName . '');
            $this->result(null, false);

            return;
        }

        // формируем запросы
        $requests = [];

        foreach ($this->requests as $request) {
            if ($request->hasCache()) {
                $result = $request->getCache();
                $this->result($request->getId(), $result->success, $result->data, $result->error);
            } else {
                $requests[] = $request->getRequest();
            }
        }

        // если нет запросов - ничего не делаем
        if (!count($requests)) {
            return;
        }

        // запрос
        try {
            $response = $this->transport->execute($serviceName, $settings, $requests, $this->getRequestHeaders($requests));
        } catch (JsonRpcException $exception) {
            $this->logError(\sprintf(
                'JsonRpc call error (%s): %s. %s',
                $serviceName,
                $exception->getMessage(),
                $this->getLogInfo($requests, null)
            ));

            $this->result(null, false, null, $exception->getMessage());

            return;
        }

        // ошибка декодирования Json
        if (null === $response) {
            $this->logError('Error parsing response from Api. An error has occurred on the server. ' . $this->getLogInfo($requests, $response));
            $this->result(null, false);

            return;
        }

        if (is_array($response)) {
            // если вернулся массив результатов
            foreach ($response as $result) {
                if (!$this->parseResult($result)) {
                    $this->logError('JsonRpc error (' . $connectionName . '). ' . $this->getLogInfo($requests, $response));
                }
            }
        } else {
            if (!$this->parseResult($response)) {
                $this->logError('JsonRpc error (' . $connectionName . '). ' . $this->getLogInfo($requests, $response));
            }
        }
        $this->requests = [];
    }

    /**
     * @param $result
     *
     * @return bool
     */
    protected function parseResult($result)
    {
        if (!empty($result->error)) {
            $this->result(!empty($result->id) ? $result->id : null, false, null, $result->error);

            return false;
        } else {
            $this->result(!empty($result->id) ? $result->id : null, true, $result->result);

            // если надо - кешируем результат
            if (!empty($result->id) && $this->requests[$result->id]->wantCache()) {
                $this->requests[$result->id]->setCache($this->results[$result->id]);
            }

            return true;
        }
    }

    /**
     * Заполняет результат указанными данными
     *
     * @param string $id ID вызова. Если NULL, то будет заполнен результат всех вызовов
     * @param bool $success Успешен ли вызов
     * @param object $data Ответ вызова
     * @param object $error Текст ошибки
     */
    protected function result($id, $success, $data = null, $error = null)
    {
        if (null === $id) {
            foreach ($this->results as $key => $value) {
                if (null !== $key) {
                    $this->result($key, $success, $data, $error);
                }
            }
        } else {
            if (!isset($this->results[$id])) {
                $this->results[$id] = new Response();
            }

            $this->results[$id]->success = $success;
            if (null !== $data) {
                $this->results[$id]->data = $data;
            }
            if (null !== $error) {
                $this->results[$id]->error = $error;
            }
        }
    }

    private function getLogInfo($requests, $response)
    {
        return 'Request: ' . \json_encode($requests) . '. Response: ' . \json_encode($response);
    }

    /**
     * Возвращает имя текущего сервиса
     * @return string
     */
    public function getServiceName()
    {
        // имя сервиса
        if ($this->serviceName === null) {
            return config('jsonrpcclient.default');
        } else {
            return $this->serviceName;
        }
    }

    public function getConnectionName()
    {
        // имя сервиса
        if ($this->connectionName === null) {
            return $this->getServiceName();
        } else {
            return $this->connectionName;
        }
    }

    /**
     * Возвращает настройки подключения к сервису
     *
     * @param string $serviceName
     *
     * @return array
     */
    protected function getConnectionOptions($serviceName)
    {
        return [
            'host' => config('jsonrpcclient.connections.' . $serviceName . '.url'),
            'key' => config('jsonrpcclient.connections.' . $serviceName . '.key', null),
            'authHeader' => config('jsonrpcclient.connections.' . $serviceName . '.authHeaderName', null),
            'additionalHeaders' => \array_merge(
                config('jsonrpcclient.additionalHeaders', []),
                config('jsonrpcclient.connections.' . $serviceName . '.additionalHeaders', [])
            ),
        ];
    }

    protected function getRequestHeaders(array $requests)
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            if (!$value) {
                continue;
            }

            if (\is_callable($value)) {
                $value = \call_user_func($value, $requests);
            }

            $headers[] = "{$name}: {$value}";
        }

        return $headers;
    }

    /**
     * Создает новый запрос
     *
     * @param string $method
     * @param array $params
     *
     * @return Request
     */
    protected function createRequest($method, $params)
    {
        return new Request($this->getServiceName(), $method, $params, config('jsonrpcclient.clientName'), $this->cache);
    }

    protected function logError(string $error): void
    {
        Log::error($error);
    }
}
