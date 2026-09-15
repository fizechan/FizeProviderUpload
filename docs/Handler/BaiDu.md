# BaiDu

命名空间：`Fize\Provider\Upload\Handler`

源码：`src/Handler/BaiDu.php`

百度智能云 BOS（Baidu Object Storage）处理器。内部持有 `BaiduBce\Services\Bos\BosClient`。

[← Azure](Azure.md) · [手册首页](../index.md) · [下一章：Google →](Google.md)

## 类声明

```php
class BaiDu extends UploadHandlerAbstract implements UploadHandler
```

| 属性 | 类型 | 说明 |
|------|------|------|
| `$bosClient` | `BaiduBce\Services\Bos\BosClient` | 构造时创建 |

## 依赖

官方 PHP SDK 以 `BaiduBce.phar` 分发，需加入 Composer 自动加载。项目 `autoload-dev` 示例：

```json
{
    "autoload": {
        "files": [
            "realpath/BaiduBce.phar"
        ]
    }
}
```

下载说明见 [百度云开发者文档](https://cloud.baidu.com/doc/Developer/index.html?sdk=php)。本仓库开发环境约定路径为 `extend/BaiduBce.phar`（该目录被 `.gitignore` 忽略）。

## 构造与配置

```php
public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
```

`$providerCfg`、`$tempDir` 未写入属性。构造会用 `assertHasKey` 校验：

| 路径 | 说明 |
|------|------|
| `$cfg['config']` | 传给 `BosClient` 的数组 |
| `$cfg['config']['credentials']` | 凭证 |
| `credentials['accessKeyId']` | Access Key |
| `credentials['secretAccessKey']` | Secret Key |
| `$cfg['config']['endpoint']` | 如 `http://bj.bcebos.com` |
| `$cfg['bucket']` | 桶名 |

`$cfg['domain']` 缺省时等于 `endpoint`。之后 `$this->cfg = $cfg`，`$this->bosClient = new BosClient($cfg['config'])`。

上传过程还读取：

| 键 | 用途 |
|----|------|
| `tempDir` | Base64 / 远程 / 分块中转 |
| `private` | 签名 URL |
| `rootPath` | `handleUpload` 计算 path |

```php
use Fize\Provider\Upload\Handler\BaiDu;

$uploader = new BaiDu([
    'config' => [
        'credentials' => [
            'accessKeyId'     => 'your-access-key-id',
            'secretAccessKey' => 'your-secret-access-key',
        ],
        'endpoint' => 'http://bj.bcebos.com',
    ],
    'bucket'  => 'your-bucket',
    'domain'  => 'https://your-bucket.bj.bcebos.com',
    'tempDir' => sys_get_temp_dir(),
    'private' => false,
]);
```

## 普通上传

### uploadFile

逻辑与 [ALiYun::uploadFile](ALiYun.md) 相同，底层改为：

```php
$this->bosClient->putObjectFromFile($bucket, $key, $filePath);
```

`$key` 为空时生成 `{Ym}/{d}/{uniqid}.{ext}`。

### uploadBase64 / uploadRemote

先落地 `tempDir`，再 `putObjectFromFile` 或复用 `uploadFile`，然后删除临时文件。

### upload / uploads → handleUpload

用 `$tmpName` 调用 `putObjectFromFile($bucket, $key, $tmpName)`，随后 `unlink($tmpName)`。`$key` 为 null 时不安全；`path` 依赖未默认提供的 `rootPath`。

## 分块上传

意图为 BOS Multipart：`initiateMultipartUpload` → `uploadPartFromFile` → `completeMultipartUpload`。

### 签名差异

`uploadLargeInit($blobCount, $uuid)`、`uploadLargeComplete($uuid, $extension)`，均无接口中的 `$key`。

### uploadLargeInit

方法体开头使用了**未定义**的 `$file_key`、`$type`：

```php
if (is_null($file_key)) {
    $save_name = uniqid();
    $sdir = $this->getSaveDir($type);
    $file_key = $sdir . '/' . $save_name;
}
```

在 PHP 7.1+ 会 Notice/Warning，在更高版本可能 Error。随后若已有旧任务则 `abortMultipartUpload($bucket, $uuid, $uploadId)`。`initiateMultipartUpload` 的 object key 传的是 `$uuid`，可根据 `$file_key` 后缀设置 `CONTENT_TYPE`。保存 `uuid`、`blobCount`、`uploadId`，无 `tempName` / `key` / `parts`。

### uploadLargePart

`PartNumber` 按 `ETags` 递增，但写入的键是 `eTags`（小写 e），complete 却 `assertHasKey($info, 'ETags')`，键名不一致。内容追加到 `$info['tempName']`，该字段同样未初始化。

响应 ETag 来自 `$response->metadata['etag']`。

### uploadLargeComplete / uploadLargeAbort

与 ALiYun 同结构，依赖未写入的 `key`、`tempName`。Abort 使用 `$info['key']` 作为对象键。

### uploadLarge / uploadParts

完成条件看 `$info['parts']`，init 未设置。不要依赖一站式自动完成。

## getAuthorizedUrl

```php
public function getAuthorizedUrl(string $url, int $expires = 0): string
```

`$cfg['private']` 为真时，从 URL path 去掉首字符 `/` 作为 key，调用：

```php
$this->bosClient->generatePreSignedUrl($bucket, $key, [
    BosOptions::SIGN_OPTIONS => [
        SignOptions::TIMESTAMP             => new DateTime(),
        SignOptions::EXPIRATION_IN_SECONDS => $expires,
    ],
]);
```

第一个参数按**完整 URL**解析，与接口 `$key` 字面含义不同。

## 实现备注

- 普通 `uploadFile` / Base64 / Remote 路径相对完整，单测也覆盖了这些方法。
- 分块初始化存在未定义变量，生产环境勿用当前分块 API。
- 工厂 `create('BaiDu', $cfg)` 可用（不需要 `$providerCfg`），但运行环境必须能自动加载 `BaiduBce.phar`。

## 相关章节

- [ALiYun](ALiYun.md) — 几乎相同的上传与 Multipart 结构
- [QiNiu](QiNiu.md) — 另一种国内对象存储，分块策略不同（本地合并）

---

[← Azure](Azure.md) · [手册首页](../index.md) · [下一章：Google →](Google.md)
