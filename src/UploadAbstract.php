<?php

namespace Fize\Provider\Upload;

use Exception;
use Fize\Codec\Json;
use Fize\Exception\FileException;
use Fize\Http\ServerRequestFactory;
use Fize\Http\UploadedFile;
use Fize\IO\File;

/**
 * 上传基类
 */
abstract class UploadAbstract
{

    /**
     * @var array provider设置
     * @todo 考虑移除
     */
    protected $providerCfg;

    /**
     * @var array 配置
     */
    protected $cfg;

    /**
     * @var string 临时文件夹路径
     */
    protected $tempDirPath;

    /**
     * @var string 允许上传的文件后缀名
     */
    protected $allowExtensions = "*";

    /**
     * @var int 允许上传的文件大小
     */
    protected $maxSize = 0;

    /**
     * @var bool 是否替换已存在文件
     */
    protected $replace = false;

    /**
     * 设置允许上传的文件后缀名
     * @param string $extensions 后缀名，多个以逗号隔开。
     */
    public function setAllowExtensions(string $extensions)
    {
        $this->allowExtensions = $extensions;
    }

    /**
     * 设置允许上传的文件大小
     * @param int $maxSize 文件大小
     */
    public function setMaxSize(int $maxSize)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * 设置是否替换已存在文件
     * @param bool $replace
     */
    public function setReplace(bool $replace = true)
    {
        $this->replace = $replace;
    }

    /**
     * 获取保存的文件夹路径部分
     * @param string|null $type 指定类型
     * @return string
     * @todo 考虑移除$type参数
     */
    protected function getSaveDir(?string $type = null): string
    {
        $ym = date('Ym');
        $dy = date('d');

        if ($type) {
            $path = $type . '/' . $ym . '/' . $dy;
        } else {
            $path = $ym . '/' . $dy;
        }
        return $path;
    }

    /**
     * 获取临时信息
     * @param string $uuid 唯一识别码
     * @return array
     */
    protected function getPartUploadInfo(string $uuid): array
    {
        $info_file = $this->tempDirPath . '/' . $uuid . '.json';
        if (!File::exists($info_file)) {
            return [];
        }
        $file = new File($info_file, 'r');
        $content = $file->getContents();
        if (!$content) {
            return [];
        }
        return Json::decode($content);
    }

    /**
     * 保存临时信息
     * @param string $uuid      唯一识别码
     * @param array  $keyValues 键值对
     */
    protected function savePartUploadInfo(string $uuid, array $keyValues)
    {
        $content = $this->getPartUploadInfo($uuid);
        $content = array_merge($content, $keyValues);
        $content = Json::encode($content, JSON_UNESCAPED_UNICODE);
        $info_file = $this->tempDirPath . '/' . $uuid . '.json';
        $file = new File($info_file, 'w');
        $file->flock(LOCK_EX);
        $file->fwrite($content);
    }

    /**
     * 删除临时信息
     * @param string $uuid 唯一识别码
     */
    protected function deletPartUploadInfo(string $uuid)
    {
        $info_file = $this->tempDirPath . '/' . $uuid. '.json';
        unlink($info_file);
    }

    /**
     * 验证值是否存在
     * @param array  $array 待验证值
     * @param string $key   键名
     * @throws Exception
     */
    protected static function assertHasKey(array $array, string $key)
    {
        if (!isset($array[$key])) {
            throw new Exception("缺少必要参数：$key");
        }
    }

    /**
     * 获取上传的文件数组
     * @param string|null $name 文件域表单名
     * @return array
     */
    protected function getUploadedFiles(?string $name = null): array
    {
        $srf = new ServerRequestFactory();
        $request = $srf->createServerRequestFromGlobals();
        $uploadFiles = $request->getUploadedFiles();
        if ($name) {
            $uploadFiles = $uploadFiles[$name] ?? [];
        }
        return $uploadFiles;
    }

    /**
     * 获取上传的文件
     * @param string $name 键名
     * @return UploadedFile
     */
    protected function getUploadedFile(string $name): UploadedFile
    {
        $uploadFiles = $this->getUploadedFiles();
        if (!isset($uploadFiles[$name])) {
            throw new FileException("找不到文件：{$name}");
        }
        return $uploadFiles[$name];
    }

    /**
     * 检查文件后缀名
     * @param string $extension 后缀名
     * @return void
     */
    protected function checkExtension(string $extension)
    {
        if ($this->allowExtensions == '*') {
            return;
        }
        $allowExtensions = explode(',', $this->allowExtensions);
        if (!in_array($extension, $allowExtensions)) {
            throw new FileException("禁止上传后缀名为{$extension}的文件");
        }
    }
}