# 用户自行部署 OCR 服务

选择可信的 OCR 服务及固定版本，按该服务官方说明部署和备份。此示例不指定第三方镜像，也不包含任何部署命令的自动执行逻辑。

1. 确认 SaiAdmin 后端能够访问 OCR 服务。容器中的 localhost 通常不是宿主机。
2. 在插件配置中填写 endpoint 和独立访问密钥。
3. 先保存，再验证已保存配置。内置 json-health 需要 GET /health 返回 version 和 capabilities，并能验证 Bearer 密钥；不符合该协议时使用自定义 ServiceProbe。
4. 外部服务的升级、重启、网络和数据备份由用户负责。插件卸载不会删除或停止该服务。
