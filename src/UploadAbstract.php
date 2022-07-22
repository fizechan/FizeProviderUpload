<?php

namespace Fize\Provider\Upload;

use Exception;
use Fize\Codec\Json;
use Fize\Image\Image;
use Fize\IO\Extension;
use Fize\IO\File;

/**
 * 上传基类
 */
abstract class UploadAbstract
{

    /**
     * @var array provider设置
     */
    protected $providerCfg;

    /**
     * @var array 配置
     */
    protected $cfg;

    /**
     * @var string 临时文件夹
     */
    protected $tempDir;

    /**
     * @var string 记录上传临时信息所用的文件名前缀
     */
    protected $tempPre = '';

    /**
     * 获取保存的文件夹路径部分
     * @param string|null $type 指定类型
     * @return string
     */
    protected function getSaveDir(?string $type): string
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
     * 如果文件是图片则根据配置进行大小调整
     * @param string      $file      文件名
     * @param string|null $extension 后缀名
     * @return array [新宽度，新高度]
     */
    protected function imageResize(string $file, ?string $extension): array
    {
        if (is_null($extension)) {
            return [null, null];
        }
        $imagewidth = null;
        $imageheight = null;
        if (Extension::isImage($extension)) {
            $imgInfo = getimagesize($file);
            $imagewidth = $imgInfo[0] ?? null;
            $imageheight = $imgInfo[1] ?? null;
            if ($imagewidth > $this->providerCfg['image_max_width'] && filesize($file) > $this->providerCfg['image_max_size']) {
                $imageheight = (int)round($this->providerCfg['image_max_width'] * $imageheight / $imagewidth);
                $imagewidth = $this->providerCfg['image_max_width'];
                Image::scale($file, $imagewidth, $imageheight);
            }
        }
        return [$imagewidth, $imageheight];
    }

    /**
     * 如果文件是图片则返回图片宽度、高度
     * @param string      $file      文件名
     * @param string|null $extension 后缀名
     * @return array [宽度，高度]
     */
    protected function getImageSize(string $file, ?string $extension): array
    {
        if (is_null($extension)) {
            return [null, null];
        }
        $imagewidth = null;
        $imageheight = null;
        if (Extension::isImage($extension)) {
            $imgInfo = getimagesize($file);
            $imagewidth = $imgInfo[0] ?? null;
            $imageheight = $imgInfo[1] ?? null;
        }
        return [$imagewidth, $imageheight];
    }

    /**
     * 获取临时信息
     * @param string $key 文件路径标识
     * @return array
     */
    protected function getPartUploadInfo(string $key): array
    {
        $infoFile = $this->tempDir . '/' . $this->tempPre . md5($key) . '.json';
        if (!File::exists($infoFile)) {
            return [];
        }
        $file = new File($infoFile, 'r');
        $content = $file->getContents();
        if (!$content) {
            return [];
        }
        $content = Json::decode($content);
        return $content;
    }

    /**
     * 保存临时信息
     * @param string $key       文件路径标识
     * @param array  $keyValues 键值对
     */
    protected function savePartUploadInfo(string $key, array $keyValues)
    {
        $content = $this->getPartUploadInfo($key);
        $content = array_merge($content, $keyValues);
        $content = Json::encode($content, JSON_UNESCAPED_UNICODE);
        $infoFile = $this->tempDir . '/' . $this->tempPre . md5($key) . '.json';
        $file = new File($infoFile, 'w');
        $file->flock(LOCK_EX);
        $file->fwrite($content);
    }

    /**
     * 删除临时信息
     * @param string $key 文件路径标识
     */
    protected function deletPartUploadInfo(string $key)
    {
        $infoFile = $this->tempDir . '/' . $this->tempPre . md5($key) . '.json';
        unlink($infoFile);
    }

    /**
     * 验证值是否存在
     * @param array  $array 待验证值
     * @param string $key   键名
     * @throws Exception
     */
    protected function assertHasKey(array $array, string $key)
    {
        if (!isset($array[$key])) {
            throw new Exception("缺少必要参数：$key");
        }
    }

    /**
     * 初始化provider设置
     * @param array $providerCfg provider设置
     */
    protected function initProviderCfg(array $providerCfg)
    {
        $defConfig = [
            'image_resize'    => true,  // 图片大小调整
            'image_max_width' => 1000,   // 图片宽度超过该值时进行调整
            'image_max_size'  => 2 * 1024 * 1024,  // 图片文件大小超过该值时进行调整
        ];
        $this->providerCfg = array_merge($defConfig, $providerCfg);
    }
}