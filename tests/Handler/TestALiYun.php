<?php

namespace tests\extend\fuli\commons\provider\upload\handler;

use fuli\commons\provider\upload\handler\ALiYun;
use PHPUnit\Framework\TestCase;
use think\facade\Config;

class TestALiYun extends TestCase
{

    public function testUploadFile()
    {
        $ali = new ALiYun();
        $file_path = 'F:\照片\浪漫夫妇.jpg';
        $data = $ali->uploadFile($file_path);
        var_dump($data);
        self::assertIsArray($data);
    }

    public function testGetPreviewUrl()
    {
        $config = Config::get('third.QiNiu.upload');
        $url = 'https://fuli-dev-media.huaruiauto.cn/202106/16/60c9c7607f9d1.mp4';
        $auth = new Auth($config['accessKey'], $config['secretKey']);
        if ($config['private']) {
            $url2 = $auth->privateDownloadUrl($url, 3600 * 24 * 365 * 100);
        } else {
            $url2 = $url;
        }
        var_dump($url2);
        self::assertNotEquals($url2, $url);
    }

    public function testUploadBase64()
    {

    }
}
