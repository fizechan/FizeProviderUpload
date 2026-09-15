# Azure

命名空间：`Fize\Provider\Upload\Handler`

源码：`src/Handler/Azure.php`

微软 Azure Blob Storage 处理器占位类。README 将其列为已支持平台，但类体为空，未实现 [`UploadHandler`](../UploadHandler.md) 的任何方法。

[← AWS](AWS.md) · [手册首页](../index.md) · [下一章：BaiDu →](BaiDu.md)

## 类声明

```php
class Azure extends UploadHandlerAbstract implements UploadHandler
{
}
```

可以实例化，调用上传方法会致命错误。基类三个 setter 可用但无效果。

## 当前不可用

- 无配置、无 Azure SDK 依赖、无单测

---

[← AWS](AWS.md) · [手册首页](../index.md) · [下一章：BaiDu →](BaiDu.md)
