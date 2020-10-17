<?php

namespace Alltube;

use Symfony\Component\ErrorHandler\Debug;

/**
 * Class ConfigFactory
 * @package Alltube
 */
class ConfigFactory
{

    /**
     * @return Config
     * @throws Exception\ConfigException
     */
    public static function create()
    {
        $configPath = __DIR__ . '/../config/config.yml';
        if (is_file($configPath)) {
            $config = Config::fromFile($configPath);
        } else {
            $config = new Config();
        }
        if ($config->uglyUrls) {
            $container['router'] = new UglyRouter();
        }
        if ($config->debug) {
            /*
             We want to enable this as soon as possible,
             in order to catch errors that are thrown
             before the Slim error handler is ready.
             */
            Debug::enable();
        }

        return $config;
    }
}
