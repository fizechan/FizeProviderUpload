# ALiYun

命名空间：`Fize\Provider\Upload\Handler`

源码：`src/Handler/ALiYun.php`

阿里云 OSS 处理器。内部持有 `OSS\OssClient`，普通上传走 `uploadFile` / `uploadPart`，私有桶用 `signUrl` 生成临时地址。

[← Local](Local.md) · [手册首页](../index.md) · [下一章：AWS →](AWS.md)

## 类声明

```php
class ALiYun extends UploadHandlerAbstract implements UploadHandler
```

| 属性 | 类型 | 说明 |
|------|------|------|
| `$ossClient` | `OSS\OssClient` | 构造时创建 |

## 依赖

```bash
composer require aliyuncs/oss-sdk-php
```

开发依赖约束示例：`aliyuncs/oss-sdk-php: ^2.7.2`。

## 构造与配置

```php
public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
```

默认 `$cfg` 为空数组后与传入值合并。`$providerCfg`、`$tempDir` 未使用。`$tempDirPath` 保持基类默认 `./temp`。

构造立即执行：

```php
new OssClient($cfg['accessKeyId'], $cfg['accessKeySecret'], $cfg['endpoint']);
```

实际还会用到但未在默认配置中给出的键：

| 键 | 用途 |
|----|------|
| `accessKeyId` | OSS 密钥 |
| `accessKeySecret` | OSS 密钥 |
| `endpoint` | 地域节点，如 `oss-cn-hangzhou.aliyuncs.com` |
| `bucket` | 存储桶，上传与 Multipart 必填 |
| `domain` | 拼公开 URL |
| `tempDir` | Base64 / 远程 / 分块的本地中转目录（**不是**基类 `$tempDirPath`） |
| `private` | 为真时 `getAuthorizedUrl` 走签名 |
| `rootPath` | `handleUpload` 计算 `path` 时使用，默认配置没有此项 |

```php
use Fize\Provider\Upload\Handler\ALiYun;

$uploader = new ALiYun([
    'accessKeyId'     => 'your-access-key-id',
    'accessKeySecret' => 'your-access-key-secret',
    'endpoint'        => 'oss-cn-hangzhou.aliyuncs.com',
    'bucket'          => 'your-bucket',
    'domain'          => 'https://your-bucket.oss-cn-hangzhou.aliyuncs.com',
    'tempDir'         => sys_get_temp_dir(),
    'private'         => false,
]);
```

## 已对齐接口的方法

### uploadFile

校验后缀后，若 `$key` 为空则生成 `{Ym}/{d}/{uniqid}.{ext}`。调用：

```php
$this->ossClient->uploadFile($bucket, $key, $filePath);
```

`path` 等于 `key`，`url` 为 `domain + '/' + key`。返回含 `orig_name`、`orig_path`。

### uploadBase64

解码到 `{tempDir}/{uniqid}.{ext}`，再 `uploadFile` 到 OSS，计算 SHA1 后 `unlink` 临时文件。

### uploadRemote

下载到 `tempDir`，调用 `uploadFile`，删除临时文件，去掉 `orig_name`，加上 `orig_url`。

### upload / uploads

进入 `handleUpload()`：校验 MIME/后缀后，用 **PHP 上传临时文件路径** 调用 `ossClient->uploadFile($bucket, $key, $tmpName)`，然后 `unlink($tmpName)`。

`$key` 为 `null` 时仍原样传给 OSS。`path` 用 `str_replace(realpath($cfg['rootPath']), '', $key)` 计算，`rootPath` 未配置时结果不可预期。表单上传请显式传入 `$key`。

## 分块上传

走 OSS Multipart：`initiateMultipartUpload` → `uploadPart` → `completeMultipartUpload` / `abortMultipartUpload`。

### 与接口的签名差异

| 方法 | 接口 | 本类 |
|------|------|------|
| `uploadLargeInit` | `($blobCount, $key, $uuid)` | `($blobCount, $uuid)`，无 `$key` |
| `uploadLargeComplete` | `($uuid, $key, $extension)` | `($uuid, $extension)`，无 `$key` |

### uploadLargeInit

若已有临时信息，先 `abortMultipartUpload($bucket, $uuid, $info['uploadId'])`（此处用 uuid 当 OSS object key）。然后 `initiateMultipartUpload($bucket, $uuid)`，保存 `uuid`、`blobCount`、`uploadId`。

**未写入** `key`、`tempName`、`parts`。

### uploadLargePart

读取 `uploadId`。`PartNumber` 按已有 `ETags` 递增。把内容 **追加** 到 `$cfg['tempDir'] . '/' . $info['tempName']`，再用 `OSS_SEEK_TO` / `OSS_LENGTH` 上传该段。`tempName` 在 init 中从未赋值，本方法在当前源码下无法正常工作。

`uploadLarge()` 完成判断使用 `$info['parts']`，init 同样未设置该键。

### uploadLargeComplete

要求 `uploadId`、`ETags`。`completeMultipartUpload($bucket, $uuid, $uploadId, $ETags)` 后按 `$info['key']`、`$info['tempName']` 取本地文件算大小/MIME/SHA1。这两个键同样未在 init 中写入。

### uploadLargeAbort

`abortMultipartUpload($bucket, $info['key'], $uploadId)`。中止用 `info['key']`，初始化中止旧任务用 `$uuid`，二者可能不是同一对象键。

### uploadLarge / uploadParts

编排逻辑拷贝自 Local。因缺少 `parts` 与 `tempName`，不要依赖自动完成；即便分步调用，也需先补全源码中的元数据。

## uploadParts1（非接口）

历史方法，**不是** `UploadHandler` 的一部分：

```php
public function uploadParts1(array $parts, string $extension = null, string $type = null, string $file_key = null): array
```

将全部分片追加到本地临时文件，再 `OssClient::multiuploadFile()`。返回字段为旧版风格（`file_size`、`mime_type`、`image_width` 等），并调用本类中**不存在**的 `imageResize()`。不应在新代码中使用。

## getAuthorizedUrl

```php
public function getAuthorizedUrl(string $url, int $expires = 0): string
```

参数名是 `$url` 而非接口的 `$key`。仅当 `$cfg['private']` 为真时：

1. `parse_url($url, PHP_URL_PATH)`
2. 去掉开头 `/` 作为 object key
3. `$ossClient->signUrl($bucket, $key, $expires)`

公开桶原样返回第一个参数。传入对象 key 而不是完整 URL 时，`parse_url` 结果通常不正确。

## 实现备注

- 构造函数不校验必填项，缺键时由 `OssClient` 抛错。
- 分块元数据与 Local 不兼容，且当前不完整。
- `handleUpload` 会删除 PHP 临时文件，可能影响后续框架逻辑。
- 工厂 `create('ALiYun', $cfg)` 可用，因本类不依赖 `$providerCfg`。

## 相关章节

- [UploadHandler](../UploadHandler.md) — 接口签名对照
- [HuaWei](HuaWei.md) — 同类 OSS 兼容实现，Multipart 也有类似缺口

---

[← Local](Local.md) · [手册首页](../index.md) · [下一章：AWS →](AWS.md)
