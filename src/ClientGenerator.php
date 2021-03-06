<?php

namespace Nbz4live\JsonRpc\Client;

use Illuminate\Console\Command;

class ClientGenerator extends Command
{
    protected $signature = 'jsonrpc:generateClient {connection?}';

    protected $description = 'Generate proxy-client for JsonRpc server by SMD-scheme';

    public function handle()
    {
        $connectionName = $this->argument('connection');
        if ($connectionName === null) {
            $connections = config('jsonrpcclient.connections');
            foreach ($connections as $connectionName => $connection) {
                $this->generateClient($connection, $connectionName);
            }
        } else {
            $config = config('jsonrpcclient.connections.' . $connectionName);
            if ($config === null) {
                $this->output->error('Connection "' . $connectionName . '" not found!');

                return;
            }
            $this->generateClient($config, $connectionName);
        }
    }

    /**
     * Генерация клиента
     *
     * @param array  $connection Настройки подключения
     * @param string $name Имя соединения
     *
     * @return bool
     */
    protected function generateClient($connection, $name)
    {
        $smd = $this->getSmdScheme($connection['url'] . '?smd');

        if (!$smd) {
            return false;
        }

        if (!$this->checkSmd($smd)) {
            return false;
        }

        $this->checkGenerator($smd);

        $this->checkHeaders($smd, $connection);

        if (!$this->generateClass($smd, $connection, $name)) {
            return false;
        }

        return true;
    }

    /**
     * Получение SMD-схемы от сервера
     *
     * @param string $host Адрес сервера
     *
     * @return bool|mixed
     */
    protected function getSmdScheme($host)
    {
        $this->info('Loading SMD from host ' . $host);
        $headers = ['Content-type: application/json'];

        foreach (config('jsonrpcclient.additionalHeaders') as $key => $value) {
            $value = \is_callable($value) ? $value() : $value;

            if (null === $value) {
                continue;
            }

            $headers[] = "{$key}: $value";
        }

        $curl = curl_init($host);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

        $json_response = curl_exec($curl);
        $error = \curl_error($curl);
        curl_close($curl);
        $result = json_decode($json_response);
        if ($result === null) {
            $this->output->error('The host did not return the SMD-scheme. Generating a client is not possible: ' . $error);

            return false;
        }

        return $result;
    }

    /**
     * Проверка версии SMD
     *
     * @param array $smd SMD-схема
     *
     * @return bool
     */
    protected function checkSmd($smd)
    {
        if (empty($smd->SMDVersion) || $smd->SMDVersion !== '2.0') {
            $this->output->error('Host returned an invalid SMD-scheme. Generating a client is not possible.');

            return false;
        }

        return true;
    }

    /**
     * Проверка генератора SMD
     *
     * @param string $smd SMD-схема
     */
    protected function checkGenerator($smd)
    {
        if (empty($smd->generator) || $smd->generator !== 'Tochka/JsonRpc') {
            $this->output->note('The host is using an unsupported JsonRpc server. There is a possibility of incorrect work of the client.');
        }
    }

    /**
     * Проверка необходимости передачи авторизационных заголовков
     *
     * @param array $smd SMD-схема
     * @param array $connection Настройки подключения
     */
    protected function checkHeaders($smd, $connection)
    {
        if (empty($smd->additionalHeaders)) {
            return;
        }

        foreach ($smd->additionalHeaders as $header => $value) {
            if ($value === '<AuthToken>' && $connection['authHeaderName'] !== $header) {
                $this->output->note('The authorization header in the connection settings is different from what the server expects.');
                $this->line('Right Header: ' . $header . ', Your Header: ' . $connection['authHeaderName']);

                return;
            }
        }
    }

    /**
     * Непосредственно генерация прокси-класса
     *
     * @param array  $smd SMD-схема
     * @param array  $connection Настройки подключения
     * @param string $connectionName Название
     *
     * @return bool
     */
    protected function generateClass($smd, $connection, $connectionName)
    {
        $classInfo = $this->getClassInfo($connection);
        if (null === $classInfo) {
            return false;
        }

        if (!empty($smd->namedParameters)) {
            $methods = $this->getMethods($smd);
        } else {
            $methods = '';
        }

        $serviceName = $connection['name'] ?? $connectionName;

        $classSource = <<<php
<?php

namespace {$classInfo['namespace']};

use Nbz4live\JsonRpc\Client\Client;

/**
 * @SuppressWarnings(PHPMD)
 */
class {$classInfo['name']} extends Client
{
    protected \$connectionName = '{$connectionName}';

    protected \$serviceName = '{$serviceName}';{$methods}
}

php;
        file_put_contents($classInfo['filePath'], $classSource);

        $this->output->success('Client class "' . $connection['clientClass'] . '" successfully generated.');

        return true;
    }

    /**
     * Возвращает информацию о классе
     *
     * @param array $connection
     *
     * @return array
     */
    protected function getClassInfo($connection)
    {
        if (empty($connection['clientClass'])) {
            $this->output->error('The class name for the client is not specified. Specify in the settings parameter "clientClass".');

            return null;
        }
        $class = explode('\\', $connection['clientClass']);

        $result['name'] = array_pop($class);
        $result['namespace'] = trim(implode('\\', $class), '\\');

        $directory = $this->getNamespaceDirectory($result['namespace']);
        if ($directory === false) {
            $this->output->error('Specified namespace not found.');

            return null;
        }

        if (!file_exists($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                $this->output->error('Can not create folder "' . $directory . '" to save class.');

                return null;
            }
        }

        $result['filePath'] = $this->getNamespaceDirectory($result['namespace']) . DIRECTORY_SEPARATOR . $result['name'] . '.php';

        return $result;
    }

    /**
     * Возвращает реализацию методов (для маппинга ассоциативных параметров)
     *
     * @param \stdClass $smd
     *
     * @return string
     */
    protected function getMethods($smd)
    {
        $result = [];
        $prefix = '';

        foreach ($smd->services as $methodName => $methodInfo) {
            $parameters = [];
            $array = [];

            if (!empty($methodInfo->parameters)) {
                $i = 0;
                foreach ($methodInfo->parameters as $param) {
                    if (empty($param->name)) {
                        $paramStr = '$param' . ++$i;
                        $arrayStr = '$param' . $i;
                    } else {
                        $paramStr = '$' . $param->name;
                        $arrayStr = "'" . $param->name . "' => $" . $param->name;
                    }

                    if (isset($param->default)) {
                        $paramStr .= ' = ' . str_replace("\n", '', var_export($param->default, true));
                    } elseif (!empty($param->optional)) {
                        $paramStr .= ' = null';
                    }
                    $parameters[] = $paramStr;
                    $array[] = $arrayStr;
                }
            }
            $parameters = implode(', ', $parameters);

            if (\count($array) > 1) {
                $arrayStr = implode(",\n            ", $array);
                $array = "\n            {$arrayStr},\n        ";
            } elseif (\count($array)) {
                $array = $array[0];
            } else {
                $array = '';
            }

            if (!empty($result)) {
                $result[] = '';
            }

            $result[] = "public function {$methodName}({$parameters})";
            $result[] = '{';
            $result[] = "    return \$this->_call('{$methodName}', [{$array}]);";
            $result[] = '}';
        }

        if (count($result)) {
            $prefix = "\n\n    ";
        }

        return \preg_replace('/^([[:blank:]]{4})$/m', '', $prefix . implode("\n    ", $result));
    }

    /**
     * Возвращает папку, в которой должен располагаться класс по его namespace
     *
     * @param string $namespace
     *
     * @return bool|string
     */
    private function getNamespaceDirectory($namespace)
    {
        $composerNamespaces = $this->getDefinedNamespaces();

        $namespaceFragments = explode('\\', trim($namespace, '\\'));
        $undefinedNamespaceFragments = [];

        while ($namespaceFragments) {
            $possibleNamespace = implode('\\', $namespaceFragments) . '\\';

            if (array_key_exists($possibleNamespace, $composerNamespaces)) {
                $path = app()->basePath() . DIRECTORY_SEPARATOR . $composerNamespaces[$possibleNamespace] . implode('/', array_reverse($undefinedNamespaceFragments));

                return realpath($path);
            }

            $undefinedNamespaceFragments[] = array_pop($namespaceFragments);
        }

        return false;
    }

    /**
     * Возвращает список объявленных namespace
     * @return array
     */
    private function getDefinedNamespaces()
    {
        $composerJsonPath = app()->basePath() . DIRECTORY_SEPARATOR . 'composer.json';
        $composerConfig = json_decode(file_get_contents($composerJsonPath));

        return (array)$composerConfig->autoload->{'psr-4'};
    }
}
