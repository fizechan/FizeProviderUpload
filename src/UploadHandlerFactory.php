<?php

namespace Fize\Provider\Upload;

use InvalidArgumentException;


/**
 * 文件上传工厂
 */
class UploadHandlerFactory
{

    /**
     * @var array 使用数组使每个驱动不互相影响
     */
    private static $handlers;

    /**
     * 设置单例
     * @param string $handler 处理器
     * @param array  $config  配置
     */
    public static function setInstance(string $handler, array $config = [])
    {
        $class = '\\' . __NAMESPACE__ . '\\Handler\\' . $handler;
        self::$handlers[$handler] = new $class($config);
    }

    /**
     * 取得单例
     * @param string $handler 处理器
     * @return UploadHandler
     */
    public static function getInstance(string $handler): UploadHandler
    {
        if (!isset(self::$handlers[$handler])) {
            throw new InvalidArgumentException("Handler '$handler' not found.");
        }
        return self::$handlers[$handler];
    }

    /**
     * 创建实例
     * @param string $handler 处理器
     * @param array  $config  配置
     * @return UploadHandler
     */
    public static function create(string $handler, array $config = []): UploadHandler
    {
        $class = '\\' . __NAMESPACE__ . '\\Handler\\' . $handler;
        return new $class($config);
    }
}
