<?php

namespace Fize\Provider\Upload;

use Fize\Image\Image;

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
}