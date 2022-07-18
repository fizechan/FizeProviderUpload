<?php

namespace fuli\commons\provider\upload;

use think\facade\Config;

/**
 * 文件上传
 */
class Upload
{

    /**
     * @var array 使用数组使每个驱动不互相影响
     */
    private static $handlers;

    /**
     * 取得单例
     * @param string|null $handler 使用的实际接口名称
     * @return UploadHandler
     */
    public static function getInstance(string $handler = null): UploadHandler
    {
        if (!$handler) {
            $handler = Config::get('provider.upload.handler');
        }

        if (!isset(self::$handlers[$handler])) {
            $class = '\\' . __NAMESPACE__ . '\\handler\\' . $handler;
            self::$handlers[$handler] = new $class();
        }
        return self::$handlers[$handler];
    }
}
