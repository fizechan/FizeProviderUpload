<?php

namespace Fize\Provider\Upload;


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
     * @param string|null $handler     使用的实际接口名称
     * @param array       $cfg         配置
     * @param array       $providerCfg provider设置
     * @param string|null $tempDir     临时文件存放文件夹目录
     * @return UploadHandler
     */
    public static function getInstance(string $handler, array $cfg = [], array $providerCfg = [], string $tempDir = null): UploadHandler
    {
        if (!isset(self::$handlers[$handler])) {
            $class = '\\' . __NAMESPACE__ . '\\Handler\\' . $handler;
            self::$handlers[$handler] = new $class($cfg, $providerCfg, $tempDir);
        }
        return self::$handlers[$handler];
    }
}
