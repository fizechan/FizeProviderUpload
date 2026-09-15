# HuaWei

命名空间：`Fize\Provider\Upload\Handler`

源码：`src/Handler/HuaWei.php`

华为云 OBS 处理器。内部持有 `Obs\ObsClient`，析构时 `close()`。

[← Google](Google.md) · [手册首页](../index.md) · [下一章：PingAn →](PingAn.md)

## 类声明

```php
class HuaWei extends UploadHandlerAbstract implements UploadHandler
```

| 属性 | 类型 | 说明 |
|------|------|------|
| `$obsClient` | `Obs\ObsClient` | 构造时创建 |

## 依赖

```bash
composer require obs/esdk-obs-php
```

开发依赖约束示例：`obs/esdk-obs-php: ^3.24.9`。

## 构造与析构

```php
public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
public function __destruct()
```

`$providerCfg`、`$tempDir` 未使用。默认 `$cfg` 为空。构造：

```php
$this->obsClient = new ObsClient([
    'key'      => $cfg['key'],
    'secret'   => $cfg['secret'],
    'endpoint' => $cfg['endpoint'],
]);
```

析构调用 `$this->obsClient->close()`。

上传还使用：

| 键 | 用途 |
|----|------|
| `key` / `secret` / `endpoint` | 客户端 |
| `bucket` | 桶 |
| `domain` | 公开 URL |
| `tempDir` | Base64 / 远程中转 |
| `private` | 签名 URL |
| `rootPath` | `handleUpload` 的 path 计算 |

```php
use Fize\Provider\Upload\Handler\HuaWei;

$uploader = new HuaWei([
    'key'      => 'your-access-key',
    'secret'   => 'your-secret-key',
    'endpoint' => 'obs.cn-east-3.myhuaweicloud.com',
    'bucket'   => 'your-bucket',
    'domain'   => 'https://your-bucket.obs.cn-east-3.myhuaweicloud.com',
    'tempDir'  => sys_get_temp_dir(),
    'private'  => false,
]);
```

## 普通上传

底层统一为：

```php
$this->obsClient->putObject([
    'Bucket'     => $this->cfg['bucket'],
    'Key'        => $key,
    'SourceFile' => $filePath, // 或临时文件
]);
```

`uploadFile` / `uploadBase64` 的返回数组含 `extend`（SDK 响应）。`uploadRemote` 复用 `uploadFile` 后改写 `orig_url`。

`handleUpload` 同样在 `$key` 为 null 时直接 `putObject`，并 `unlink($tmpName)`。返回含 `extend`，无 `tmp_name`。

## 分块上传

意图调用 OBS：`initiateMultipartUpload` → `uploadPart` → `completeMultipartUpload`。

### 签名差异

`uploadLargeInit($blobCount, $uuid)`、`uploadLargeComplete($uuid, $extension)`，无 `$key`。

### 未定义变量与键名混用

| 位置 | 问题 |
|------|------|
| `uploadLargeInit` | 中止旧任务使用未定义 `$key`；`initiateMultipartUpload` 使用未定义 `$file_key`；保存的是 `$resp['uploadId']` |
| `uploadLargePart` | 断言 `UploadId`（大写），init 写入的是 `uploadId`；`uploadPart` 使用未定义 `$file_key`；`savePartUploadInfo($file_key, …)` 未用 `$uuid`；**方法没有 `return`** |
| `uploadLargeComplete` | 断言小写 `uploadId` 与 `ETags`，complete 又读 `UploadId`；`Key` 为未定义 `$file_key` |
| `uploadLargeAbort` | `getPartUploadInfo($file_key)` 使用未定义变量 |

当前源码下分块 API 不能正常工作。`uploadLarge()` 在 part 之后访问 `$info['parts']`，而 `uploadLargePart` 不返回数组，会在这里出错。

### uploadParts1（非接口）

旧版方法：调用 `uploadLargeInit($file_key, $type)`（与当前 init 签名不匹配），再 part/complete，然后 `getObjectMetadata`。无后缀时用**未导入**的 `Fso::getExtensionFromMime` 猜测并 `copyObject` + `deleteObject`。新代码不要调用。

## getAuthorizedUrl

```php
public function getAuthorizedUrl(string $url, int $expires = 0): string
```

`$expires === 0` 时改为 `2592000`（30 天，注释称 OBS 上限）。`$cfg['private']` 为真时：

```php
$resp = $this->obsClient->createSignedUrl([
    'Method'  => 'GET',
    'Bucket'  => $this->cfg['bucket'],
    'Key'     => /* URL path 去掉首 / */,
    'Expires' => $expires,
]);
return $resp['SignedUrl'];
```

第一个参数按完整 URL 解析。

## 实现备注

- `uploadFile` / Base64 有可用单测形态，相对可靠。
- 分块与 `uploadParts1` 处于半迁移状态，不要用于生产。
- 工厂 `create('HuaWei', $cfg)` 可用。

## 相关章节

- [ALiYun](ALiYun.md) — 同类对象存储 Multipart
- [PingAn](PingAn.md) — 名称同为 OBS，但是平安云且已废弃

---

[← Google](Google.md) · [手册首页](../index.md) · [下一章：PingAn →](PingAn.md)
