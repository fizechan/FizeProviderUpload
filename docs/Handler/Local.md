# Local

命名空间：`Fize\Provider\Upload\Handler`

源码：`src/Handler/Local.php`

本地磁盘上传实现。不依赖云 SDK，是本库最完整、也最接近 [`UploadHandler`](../UploadHandler.md) 契约的处理器。其他云实现多以其上传流程为模板。

[← UploadHandlerFactory](../UploadHandlerFactory.md) · [手册首页](../index.md) · [下一章：ALiYun →](ALiYun.md)

## 类声明

```php
class Local extends UploadHandlerAbstract implements UploadHandler
```

无额外公开属性。运行期使用基类的 `$cfg`、`$tempDirPath`、`$allowExtensions`、`$replace`。

## 构造与配置

```php
public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
```

`$providerCfg` 未使用。

默认 `$cfg`：

| 键 | 默认值 | 说明 |
|----|--------|------|
| `domain` | `null` | 拼 URL 的主机。为 `null` 时取 `Fize\Web\Request::domain()` |
| `rootPath` | `'.'` | 文件系统根，所有保存路径相对它拼接 |
| `saveDir` | `'/uploads'` | 上传子目录 |
| `tempDir` | `'/temp'` | 分片临时目录（相对 `rootPath`） |
| `multiple` | `false` | 源码写入但未使用 |

`$tempDir` 参数优先于 `$cfg['tempDir']`。最终：

```text
$tempDirPath = $cfg['rootPath'] . $tempDir
```

`domain` 为空时依赖当前请求；CLI 或未注入 `ServerRequestFactory` 时可能得到不符合预期的域名。建议显式配置 `domain`。

```php
use Fize\Provider\Upload\Handler\Local;

$uploader = new Local([
    'domain'   => 'https://static.example.com',
    'rootPath' => '/var/www',
    'saveDir'  => '/uploads',
    'tempDir'  => '/temp',
]);
$uploader->setAllowExtensions('jpg,png,gif,pdf');
$uploader->setReplace(false);
```

## 路径规则

`getPathInfo($key, $extension, $uuid)`（protected）决定落盘位置：

| 条件 | `key` | 目录 | 文件名 | 绝对路径 |
|------|-------|------|--------|----------|
| `$key` 为空 | `{Ym}/{d}/{uuid}.{ext}` | `{saveDir}/{Ym}/{d}` | `{uuid}.{ext}` | `{rootPath}/{dir}/{name}` |
| `$key` 有值 | 原样使用 | `dirname($key)` | `basename($key)` | `{rootPath}/{dir}/{name}` |

绝对路径经 `File::realpath(..., false)` 规范化。指定 `$key` 时**不会**再加 `saveDir`，调用方需自己包含完整相对路径。

目标已存在且 `$replace === false` 时抛 `FileException(..., '文件已存在！')`；为 `true` 则先 `unlink` 再写。

## 表单上传

### upload / uploads

`upload($name, $key)` 取单个 `UploadedFile`；`uploads($name, $keys)` 遍历同名字段数组。均进入 `handleUpload()`：

1. 读取客户端文件名、MIME；任一为空则失败。
2. `MIME::getExtensionByMime()` 得后缀；空后缀禁止上传。
3. `checkExtension()`。
4. `getPathInfo()` 后 `UploadedFile::moveTo($targetPath)`。
5. 计算 SHA1，用 `rootPath` 的 realpath 裁出 Web `path`，拼 `url = domain + path`。

返回字段：`key`、`name`、`path`、`url`、`size`、`mime`、`extension`、`sha1`、`dir`、`full_path`、`orig_name`、`tmp_name`。

```php
$info = $uploader->upload('file');
$infos = $uploader->uploads('files');
```

测试环境需：

```php
use Fize\Http\ServerRequest;
use Fize\Http\ServerRequestFactory;
use Fize\Http\UploadedFile;

$request = new ServerRequest('POST', '//example.com/upload');
$file = new UploadedFile($localPath, filesize($localPath), UPLOAD_ERR_OK);
$file->forTest();
$request = $request->withUploadedFiles(['file' => $file]);
ServerRequestFactory::setGlobals($request);
```

## 非表单上传

### uploadFile

读取本地文件后缀并校验，再 `fwrite` 复制到目标。额外返回 `orig_name`、`orig_path`。写入结果为 `0` 或 `false` 视为失败。

```php
$uploader->setReplace();
$info = $uploader->uploadFile('/data/report.pdf', 'docs/report.pdf');
```

### uploadBase64

校验 `data:{mime};base64,` 头，解码后写入。无 `orig_name`。

### uploadRemote

`file_get_contents($url)` 后写入。URL 无后缀时，写盘后再 `handleNoExtensionFile()`：按文件内容猜后缀并 `rename`。返回含 `orig_url`。

## 分块上传

本地分块**不调用云 Multipart**，而是把每块写成临时文件，完成时按 `BLOB-0` … `BLOB-n` 顺序追加合并。

### uploadLargeInit

参数与接口一致：`(?int $blobCount, ?string $key, ?string $uuid)`。

- 若传入 `$key`，则 **`$uuid = $key`**（用路径标识当任务 ID）。
- 否则 `$uuid` 默认 `uniqid()`。
- 已有旧任务则删除其 `parts` 中的文件并清 JSON。

写入的临时信息：`uuid`、`key`、`blobCount`、`parts`（空对象）。

### uploadLargePart

要求临时信息含 `parts`。`$blobIndex` 默认 `count($parts)`。临时文件名：

```text
{uuid}[-{blobCount}]-{blobIndex}.tmp
```

位于 `$tempDirPath`。`parts["BLOB-{$blobIndex}"]` 存文件名（不是绝对路径）。返回整个 `$info`。

### uploadLargeComplete

参数：`(string $uuid, ?string $key = null, ?string $extension = null)`。

- 若 init 时指定了 `blobCount`，必须与 `parts` 数量相等。
- `$key` 为空则用 init 时保存的 `key`。
- `getPathInfo($key, $extension, $uuid)` 得到最终路径，按序追加合并。
- 未指定后缀时尝试从合并结果探测，成功则给 `key`/`name`/`targetPath` 加后缀并 `rename`。
- 删除全部分片文件与 JSON。

返回含 `uuid`、`full_path` 的完整文件信息。

### uploadLargeAbort

删除分片文件与 JSON。

### uploadLarge

见 [UploadHandler · uploadLarge](../UploadHandler.md#uploadlarge)。`$blobIndex == -2` 时调用 `$this->uploadLargeComplete($uuid, $extension)`，即把 `$extension` 传给了**第二个参数 `$key`**。需要指定后缀时应走 `uploadLargeComplete($uuid, $key, $extension)`，不要依赖 `-2` 传后缀。

### uploadParts

`init` → 按数组下标 `part` → `complete`。注释写明本机其实可以直接拼接，当前仍复用分块状态机。

## getAuthorizedUrl

```php
public function getAuthorizedUrl(string $key, int $expires = 0): string
```

返回 `$this->cfg['domain'] . $key`。`$expires` 忽略。`$key` 若已是完整 URL，结果会错误拼接。本地存储没有访问签名。

## 其他受保护方法

### handleUpload

见上文表单上传。

### getPathInfo

见路径规则。第三参数 `$uuid` 仅在自动生成文件名时使用。

### handleNoExtensionFile

`uploadRemote` 在 URL 无后缀时调用。根据已写文件探测后缀并重命名。返回 `[targetPath, name, extension, key]`。注释写“返回 [文件路径, 文件名, 后缀名]”，实际是四元组。

## 相关章节

- [UploadHandlerAbstract](../UploadHandlerAbstract.md) — `getSaveDir`、分片 JSON
- [Tencent](Tencent.md) — 分块完成后改为上传 COS，本地拼片逻辑类似

---

[← UploadHandlerFactory](../UploadHandlerFactory.md) · [手册首页](../index.md) · [下一章：ALiYun →](ALiYun.md)
