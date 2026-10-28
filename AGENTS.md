# WordPress 插件开发约定

本仓库为 LiveblogAI 的独立开源 WordPress 插件，采用 GPL-2.0-or-later。

- 只通过公开 HTTP 和 Embed 协议接入服务，不引入其他应用私有源码或配置。
- 运行逻辑归 `src/`，构建和本地打包工具归 `scripts/`。
- 标识符、日志、错误与用户文案使用英文；用户文案接入 WordPress i18n；源码注释使用中文。
- 命名函数与方法保留中文 JSDoc / PHPDoc，React 函数组件与匿名回调除外。
- 不新增或运行自动化测试；验证使用 `pnpm lint`、`pnpm format:check`、`pnpm typecheck`、`pnpm build` 与真实 WordPress 人工验收。
- 保留版权、许可证、品牌与外部服务说明，不将 LiveblogAI 云服务纳入插件授权。
- 新建或调整目录前先征得用户同意。提交、push、发布和创建 PR 需要用户明确授权。
- 发布前同步 `package.json`、`src/block.json`、`src/liveblog.php` 与 `src/readme.txt` 的版本。
