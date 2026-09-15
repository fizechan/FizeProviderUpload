# PingAn

命名空间：`Fize\Provider\Upload\Handler`

源码：`src/Handler/PingAn.php`

平安云 OBS 处理器。类注释已标记：

```php
/**
 * 平安云OBS
 * @deprecated 平安云OBS已被废弃，建议使用其他云存储服务
 */
```

[← HuaWei](HuaWei.md) · [手册首页](../index.md) · [下一章：QiNiu →](QiNiu.md)

## 类声明

```php
class PingAn extends UploadHandlerAbstract implements UploadHandler
```

| 属性 | 类型 | 说明 |
|------|------|------|
| `$obsClient` | `Fize\Third\PingAn\Api\OBS` | 设计上应保存客户端，**构造函数未赋值** |

依赖第三方包 `Fize\Third\PingAn\Api\OBS`，未出现在本库 `composer.json` 的 `require` / `require-dev` 中。

## 构造与配置

```php
public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
```

默认 `$cfg`、`$providerCfg` 均为空数组合并。随后：

```php
$obs = new OBS($config['accessKey'], $config['secretKey']);
$obs->setInternalUpload($config['internalUpload']);
$obs->setBucket($config['bucket']);
```

结果保存在局部变量 `$obs`，**没有** `$this->obsClient = $obs`。之后所有 `$this->obsClient->…` 都会因空属性失败。

预期配置键（从调用点推断）：

| 键 | 用途 |
|----|------|
| `accessKey` / `secretKey` | 平安云凭证 |
| `internalUpload` | 是否走内网上传 |
| `bucket` | 桶 |
| `domain` | URL |
| `private` | 签名 |
| `tempDir` / `rootPath` | 中转与 path 计算 |

`$tempDir` 参数未应用到 `$tempDirPath`。

## 方法概要

公开方法齐备，流程模仿 Local：表单走 `handleUpload` → `$this->obsClient->putObject($tmpName, $key)`；Base64/远程先落地再 `putObject`；分块先在本地拼文件，complete 时再 `putObject` 并删除本地结果。

`getAuthorizedUrl($url, $expires)` 在 `private` 为真时对 URL path 做 `urldecode`，再 `$this->obsClient->getSignedUrl($path)`。`$expires` 未传给签名方法。

`getPathInfo()` 将合并文件放在 `$tempDirPath`，与 Local 不同（Local 合并到 `saveDir`）。

## 为什么不能使用

1. 官方已废弃平安云 OBS。
2. 客户端未赋给 `$this->obsClient`。
3. `Fize\Third\PingAn` 不是本包声明的依赖。
4. 单测 `TestPingAn` 仍复制华为云风格配置，且调用已不存在的 `getPreviewUrl()`。

新项目应改用 [Local](Local.md)、[ALiYun](ALiYun.md)、[Tencent](Tencent.md)、[QiNiu](QiNiu.md) 等。

---

[← HuaWei](HuaWei.md) · [手册首页](../index.md) · [下一章：QiNiu →](QiNiu.md)
