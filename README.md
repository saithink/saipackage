<p align="center">
  <img src="https://saithink.top/images/logo.png" width="120" />
</p>
<p align="center">
  <img src="https://svg.hamm.cn/badge.svg?key=License&value=MIT" />
  <img src="https://svg.hamm.cn/badge.svg?key=Version&value=5.x" />
</p>

<div style="padding:18px;max-width: 1024px;margin:0 auto;">
<h1>saipackage 1.x</h1>

基于<a href="https://saithink.top" target="_blank">saiadmin</a>创建的插件安装器，能够方便的管理安装`saiadmin`插件

<h1>安装</h1>

composer环境的安装命令如下

```bash
composer require saithink/saipackage
```

本插件必须在`saiadmin`环境下才能使用，请先配置`saiadmin`

## 插件依赖与配置管理（6.x）

支持插件版本依赖、冲突与卸载保护、加密配置、外部服务连接检测、运行就绪状态和持久后台安装任务。Docker 服务由用户自行部署，安装器不管理容器。

- [完整设计](docs/plugin-management-design.md)
- [启用、插件契约与 API](docs/plugin-management.md)
- [验证记录](docs/validation.md)
- [声明示例](examples/logistics_ocr/manifest.json)
- [在线版本检测与手动升级](docs/online-updates.md)：自建 JSON 源及官方商店适配，自动检测只更新缓存；下载预览后需再次手动确认。

升级后先运行 `php webman saipackage:setup`，配置稳定的 `SAIPACKAGE_SETTINGS_KEY`，再设置 `SAIPACKAGE_MANAGEMENT_ENABLED=1` 并重启、构建前端。未启用时保持旧安装流程。

<div style="clear: both">
<h1>LICENSE</h1>
This project is open-sourced software licensed under the MIT.
</div>

</div>
