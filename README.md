# FizeProviderUpload

文件上传库。
- 支持批量上传。
- 支持文件分块上传。
- 支持文件类型验证。
- 支持文件大小验证。
- 支持文件上传进度。

## 除支持本地存储(Local)外，目前已支持以下平台：

- 阿里云(ALiYun)
- 百度智能云(BaiDu)
- 华为云(HuaWei)
- 平安云(PingAn)
- 七牛云(QiNiu)

## 使用指南
- 推荐前端使用[Plupload](https://www.plupload.com/)进行文件上传。

### 本地存储(Local)


### 阿里云(ALiYun)
composer require aliyuncs/oss-sdk-php

### 百度智能云(BaiDu)
下载SDK，将下载到的文件“BaiduBce.phar”添加到自动加载路径中。
[SDK下载地址](https://cloud.baidu.com/doc/Developer/index.html?sdk=php)
```json
{
    "autoload": {
        "files": [
        "realpath/BaiduBce.phar"
        ]
    }
}
```

## TODO
- 上传图片处理独立出来。
- 前端例子。
- 前端调用也需要做个兼容层。
