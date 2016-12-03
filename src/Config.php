<?php

namespace Web;

class Config
{
    const CONFIG_DIR = '/../config';

    protected static $config = [];

    public static function get($key)
    {
        if (!array_key_exists($key, self::$config)) {
            $configPath = realpath(__DIR__ . self::CONFIG_DIR . '/' . $key . '.php');
            self::$config[$key] = require_once $configPath;
        }

        return self::$config[$key];
    }
}
