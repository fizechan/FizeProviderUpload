# FizeProviderUpload 参考手册

`fize/provider-upload` 是一套面向 PHP 的文件上传统一库。业务代码只依赖 [`UploadHandler`](UploadHandler.md) 接口，通过 [`UploadHandlerFactory`](UploadHandlerFactory.md) 或直接实例化具体处理器，即可在本地磁盘与多家对象存储之间切换。

本手册依据当前源码整理，入口为本文件。各章节以代码类名为文件名。

## 文档目录

| 章节 | 文件 | 说明 |
|------|------|------|
| [UploadHandler](UploadHandler.md) | `src/UploadHandler.php` | 上传统一接口，定义全部对外方法与约定 |
| [UploadHandlerAbstract](UploadHandlerAbstract.md) | `src/UploadHandlerAbstract.php` | 抽象基类，提供校验、路径、分片临时信息等公共能力 |
| [UploadHandlerFactory](UploadHandlerFactory.md) | `src/UploadHandlerFactory.php` | 工厂：创建实例、注册/获取单例 |
| [Local](Handler/Local.md) | `src/Handler/Local.php` | 本地磁盘存储，实现最完整 |
| [ALiYun](Handler/ALiYun.md) | `src/Handler/ALiYun.php` | 阿里云 OSS |
| [AWS](Handler/AWS.md) | `src/Handler/AWS.php` | 亚马逊 S3，仅有空类骨架 |
| [Azure](Handler/Azure.md) | `src/Handler/Azure.php` | 微软 Azure，仅有空类骨架 |
| [BaiDu](Handler/BaiDu.md) | `src/Handler/BaiDu.php` | 百度智能云 BOS |
| [Google](Handler/Google.md) | `src/Handler/Google.php` | 谷歌云，仅有空类骨架 |
| [HuaWei](Handler/HuaWei.md) | `src/Handler/HuaWei.php` | 华为云 OBS |
| [PingAn](Handler/PingAn.md) | `src/Handler/PingAn.php` | 平安云 OBS，已标记废弃 |
| [QiNiu](Handler/QiNiu.md) | `src/Handler/QiNiu.php` | 七牛云对象存储 |
| [Tencent](Handler/Tencent.md) | `src/Handler/Tencent.php` | 腾讯云 COS |

## 项目定位

Composer 包名为 `fize/provider-upload`，命名空间根为 `Fize\Provider\Upload`，许可证 MIT。

源码宣称的能力：

- 单文件 / 多文件表单上传
- 服务器本地文件、Base64、远程 URL 转存
- 分块（分片）上传：初始化、传块、完成、中止，以及一站式 `uploadLarge()` / `uploadParts()`
- 后缀名白名单、是否覆盖已存在文件
- 上传后返回路径、URL、大小、MIME、SHA1 等信息
- 私有桶场景下生成带签名的访问 URL（各处理器实现程度不同）

README 中还提到“文件大小验证”和“上传进度”。当前代码里 `setMaxSize()` 会写入属性，但各处理器上传路径均未实际校验大小；进度回调也未实现。

## 运行环境与依赖

| 项目 | 要求 |
|------|------|
| PHP | `>= 7.1.0` |
| 扩展 | `ext-json` |
| 运行时依赖 | `fize/cache`、`fize/exception`、`fize/http`、`fize/io`、`fize/web` |
| 开发依赖 | `phpunit/phpunit`，以及若干云厂商 SDK（见各处理器章节） |

本地存储无需额外 SDK。云处理器需自行安装对应 SDK，详见各 Handler 文档。百度云还需将官方 `BaiduBce.phar` 加入自动加载（开发环境已在 `composer.json` 的 `autoload-dev.files` 中指向 `extend/BaiduBce.phar`）。

推荐前端使用 [Plupload](https://www.plupload.com/) 配合分片接口。

安装：

```bash
composer require fize/provider-upload
```

## 源码结构

```
FizeProviderUpload/
├── src/
│   ├── UploadHandler.php              # 接口
│   ├── UploadHandlerAbstract.php      # 抽象基类
│   ├── UploadHandlerFactory.php       # 工厂
│   └── Handler/
│       ├── Local.php
│       ├── ALiYun.php
│       ├── AWS.php                    # 空实现
│       ├── Azure.php                  # 空实现
│       ├── BaiDu.php
│       ├── Google.php                 # 空实现
│       ├── HuaWei.php
│       ├── PingAn.php                 # 已废弃
│       ├── QiNiu.php
│       └── Tencent.php
├── tests/                             # PHPUnit，覆盖工厂与各处理器
├── composer.json
└── README.md
```

PSR-4 映射：`Fize\Provider\Upload\` → `src/`。

## 架构

```
业务代码
    │
    ├─ UploadHandlerFactory::create('Local', $cfg)
    │       或 setInstance() / getInstance()
    │
    ▼
UploadHandler（接口）
    ▲
    │  implements
    │
UploadHandlerAbstract（抽象基类）
    ▲
    │  extends
    │
Handler\Local / ALiYun / Tencent / …
```

设计要点：

1. **接口统一入口。** 所有处理器声明实现 `UploadHandler`。调用方应只依赖接口方法，不要依赖某个 Handler 上的历史遗留方法（例如 `uploadParts1()`）。
2. **基类承担横切逻辑。** 读 `$_FILES`、后缀校验、按 `Ym/d` 生成目录、分片元数据 JSON 的读写，都在 `UploadHandlerAbstract`。
3. **工厂按类名解析。** `$handler` 必须是 `Handler` 目录下的短类名，例如 `'Local'`、`'ALiYun'`、`'Tencent'`。
4. **配置分两层。** 构造函数签名为 `($cfg, $providerCfg = [], $tempDir = null)`。`$cfg` 是业务/存储配置；`$providerCfg` 仅部分云处理器使用（腾讯云 COS 客户端参数、平安云预留项）。工厂目前只把 `$config` 传给第一个参数，见 [UploadHandlerFactory](UploadHandlerFactory.md)。

## 处理器实现状态

| 处理器 | 存储后端 | 表单上传 | 本地/Base64/远程 | 分块上传 | 签名 URL | 备注 |
|--------|----------|----------|------------------|----------|----------|------|
| Local | 本地磁盘 | 可用 | 可用 | 可用（本地拼文件） | 拼接域名，无签名 | 参考实现最完整 |
| ALiYun | 阿里云 OSS | 部分可用 | 可用 | 走 OSS Multipart，实现不完整 | 私有桶 `signUrl`，参数按 URL 解析 | 接口签名与若干字段不一致 |
| AWS | 亚马逊 S3 | 未实现 | 未实现 | 未实现 | 未实现 | 空类 |
| Azure | Azure Blob | 未实现 | 未实现 | 未实现 | 未实现 | 空类 |
| BaiDu | 百度 BOS | 部分可用 | 可用 | 走 BOS Multipart，初始化有缺陷 | 私有桶预签名，参数按 URL 解析 | 依赖 `BaiduBce.phar` |
| Google | Google Cloud | 未实现 | 未实现 | 未实现 | 未实现 | 空类 |
| HuaWei | 华为云 OBS | 部分可用 | 可用 | Multipart 引用了未定义变量 | 私有桶签名 URL，最长 30 天 | 析构时关闭客户端 |
| PingAn | 平安云 OBS | 不可用 | 不可用 | 本地拼片后上传 | 私有桶签名 | 已 `@deprecated`，客户端未赋给属性 |
| QiNiu | 七牛云 | 部分可用 | 可用 | 本地拼片后再 `putFile` | 私有下载 URL | Token 走 `fize/cache` |
| Tencent | 腾讯云 COS | 可用 | 可用 | 本地拼片后 `Upload` | `getObjectUrl`，参数为 key | 必须传入 `providerCfg.region` |

“部分可用”通常表示：表单上传依赖调用方传入 `$key`，且会删除 PHP 临时文件；`$key` 为 `null` 时行为不安全。详见各处理器章节。

## 统一返回结构

成功上传后，多数方法返回关联数组。字段因处理器和上传方式略有增减，常见键如下。

| 字段 | 类型 | 含义 |
|------|------|------|
| `key` | string | 对象存储或相对路径标识 |
| `name` | string | 保存文件名 |
| `path` | string | Web / 对象路径（部分处理器等于 `key`） |
| `url` | string | 完整访问 URL（腾讯云部分方法不返回） |
| `size` | int | 字节数 |
| `mime` | string | MIME 类型 |
| `extension` | string | 后缀名（小写或探测结果） |
| `sha1` | string | 文件 SHA1 |
| `dir` | string | 目录部分 |
| `full_path` | string | 本机绝对路径（本地或临时合并文件） |
| `orig_name` | string | 原始文件名 |
| `orig_path` | string | 服务器源文件路径（`uploadFile`） |
| `orig_url` | string | 远程源 URL（`uploadRemote`） |
| `tmp_name` | string | PHP 上传临时文件路径 |
| `uuid` | string | 分块任务识别码 |
| `extend` | mixed | 云 SDK 原始响应 |

分块进行中（尚未 `complete`）时，返回的是临时信息（`uuid`、`blobCount`、`parts` 或 `uploadId` / `ETags`），不是最终文件信息。

## 分块上传约定

接口提供两套用法，可任选其一。

**分步调用（适合断点续传、跨请求）：**

1. `uploadLargeInit($blobCount, $key, $uuid)` → 得到 `$uuid`
2. 多次 `uploadLargePart($uuid, $content, $blobIndex)`
3. `uploadLargeComplete($uuid, $key, $extension)` 合并并落盘/上传
4. 失败时 `uploadLargeAbort($uuid)` 清理

**表单一站式：** `uploadLarge($name, $blobIndex, $blobCount, $extension, $uuid)`

| `$blobIndex` | 含义 |
|--------------|------|
| `-1` | 仅初始化，返回临时信息 |
| `>= 0` | 上传当前分片；若已收齐全部分片则自动完成 |
| `-2` | 仅完成上传，必须同时传 `$uuid` |

未传 `$uuid` 时，一站式方法用 `session_id() . "_upload_" . $name` 作为会话内默认识别码。

**内存分片：** `uploadParts($parts, $extension, $uuid)` 内部按序执行 init → part → complete。

分片元数据默认写在临时目录下的 `{uuid}.json`，由基类 `getPartUploadInfo()` / `savePartUploadInfo()` / `deletPartUploadInfo()` 维护。本地与七牛、平安、腾讯等“先落地再上传”的实现还会生成 `{uuid}-{blobCount}-{index}.tmp` 分片文件。

注意：接口声明的 `uploadLargeInit` / `uploadLargeComplete` 含 `$key` 参数，但阿里云、百度、华为、平安、七牛等实现省略了该参数，与接口不完全一致。调用前请对照具体处理器章节。

## 校验与安全默认值

| 能力 | 默认 | 实际行为 |
|------|------|----------|
| 后缀白名单 `setAllowExtensions()` | `*`（不限制） | 逗号分隔；`checkExtension()` 在多数上传路径中生效 |
| 大小上限 `setMaxSize()` | `0` | 仅保存属性，上传时未检查 |
| 覆盖已存在文件 `setReplace()` | `false` | 仅 [Local](Handler/Local.md) 在落盘前检查并决定是否 `unlink` |

表单上传统一通过 `fize/http` 的 `ServerRequestFactory::createServerRequestFromGlobals()` 读取已上传文件。单元测试需先 `ServerRequestFactory::setGlobals()` 注入请求。

未指定 `$key` 时，默认路径规则为 `{Ym}/{d}/{uniqid}[.{ext}]`，例如 `202609/15/68c8a1b2c3d4e.jpg`。

## 快速开始

本地存储：

```php
use Fize\Provider\Upload\Handler\Local;

$uploader = new Local([
    'domain'   => 'https://static.example.com',
    'rootPath' => '/var/www',
    'saveDir'  => '/uploads',
    'tempDir'  => '/temp',
]);
$uploader->setAllowExtensions('jpg,png,pdf');
$uploader->setReplace(false);

$info = $uploader->upload('file');          // 表单字段名
// $info['url']、$info['key']、$info['sha1'] …
```

工厂创建：

```php
use Fize\Provider\Upload\UploadHandlerFactory;

$uploader = UploadHandlerFactory::create('Local', [
    'rootPath' => '/var/www',
]);
```

需要 `$providerCfg` 的处理器（如腾讯云）不要只用工厂的两参数 `create()`，应直接 `new Tencent($cfg, $providerCfg)`。

## 文档约定

- 类、方法、配置键与源码保持一致，包括历史拼写（如 `base64Centent`、`deletPartUploadInfo`）。
- “接口约定”以 `UploadHandler` 为准；“实现差异”写在各 Handler 章节。
- 示例中的密钥、Bucket、域名均为占位符，请使用自有凭证。
- README 中的 TODO（图片处理独立、前端示例、前端兼容层）尚未实现，本手册不将其写成可用功能。

---

[下一章：UploadHandler →](UploadHandler.md)
