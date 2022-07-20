<?php

namespace Fize\Provider\Upload;

use Exception;
use Fize\Codec\Json;
use Fize\Image\Image;
use Fize\IO\File as Fso;

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
        if (Image::isImg($extension)) {
            $imgInfo = getimagesize($file);
            $imagewidth = $imgInfo[0] ?? null;
            $imageheight = $imgInfo[1] ?? null;
            if ($imagewidth > $this->providerCfg['image_max_width'] && filesize($file) > $this->providerCfg['image_max_size']) {
                $imageheight = (int)round($this->providerCfg['image_max_width'] * $imageheight / $imagewidth);
                $imagewidth = $this->providerCfg['image_max_width'];
                $image = Image::open($file);
                $image->thumb($imagewidth, $imageheight)->save($file);
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
        if (Image::isImg($extension)) {
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
        $file = new Fso($infoFile, true);
        $file->open('r');
        $content = $file->getContents();
        if ($content) {
            $content = Json::decode($content);
        } else {
            $content = [];
        }
        $file->close();
        return $content;
    }

    /**
     * 保存临时信息
     * @param string $key       文件路径标识
     * @param array  $keyValues 键值对
     */
    protected function savePartUploadInfo(string $key, array $keyValues)
    {
        $infoFile = $this->tempDir . '/' . $this->tempPre . md5($key) . '.json';
        $file = new Fso($infoFile, true);
        $file->open('r');
        $content = $file->getContents();
        if ($content) {
            $content = Json::decode($content);
        } else {
            $content = [];
        }
        $file->close();
        $content = array_merge($content, $keyValues);
        $content = Json::encode($content, JSON_UNESCAPED_UNICODE);
        $file->open('w');
        $file->lock(LOCK_EX);
        $file->write($content);
        $file->close();
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
}