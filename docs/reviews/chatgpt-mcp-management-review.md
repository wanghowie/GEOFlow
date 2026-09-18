# ChatGPT后台管理插件方案复核报告

日期：2026-09-18。  
代码基线：`9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1`。  
关联：[完善后的方案](../plans/chatgpt-mcp-management-rfc.md) · [48项验收清单](../plans/chatgpt-mcp-management-acceptance.md)。

## 复核结论

基于现有API建设只读连接具有可行性。新增写入工具前，需要完成资源授权范围确认、Core事务内状态及版本检查、异步副作用管理和实际客户端授权验收。

本次识别12项接入风险或方案缺口。下面区分源码中直接观察到的行为、由此产生的设计要求，以及尚待测试的条件。它们不构成对生产站点的漏洞确认，也不代表代码修复已完成。

### 证据范围

通过GitHub连接读取了API路由、文章与任务控制器、文章业务服务、Token与能力注册表、部分请求校验及幂等实现，同时核对仓库远程管理说明、CLI工作流、贡献规则和CI配置。参考了OpenAI当前插件认证、打包、接入说明及MCP规范。

本地Git克隆因无法解析github.com失败，后续读取和文档提交使用已授权GitHub连接完成。未运行PHP/JavaScript测试、数据库并发测试、MCP Inspector、OAuth联调或线上业务操作。未读取生产凭据或客户数据。源码链接在本报告固定到审查提交，实施时应重新核对。

## R01：MCP授权与Core Token的边界需要明确

**观察。** [ApiTokenService](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Services/Api/ApiTokenService.php)提供Sanctum Token能力。[OpenAI认证文档](https://developers.openai.com/plugins/build/auth)描述OAuth发现、PKCE、resource和客户端识别要求。单独存在Bearer Token接口不能证明已经支持完整MCP授权。

**完善。** 分离ChatGPT到MCP的OAuth凭据与MCP到Core的专属凭据；在可信页面绑定OAuth主体、Core账号和实例；按当前客户端能力选择预注册、CIMD或DCR。不得把静态管理员密钥写进插件包，不得用邮箱相同自动绑定超级管理员。

**通过要求。** 授权码流程、错误受众拒绝、scope交集、账号绑定和凭据不透传通过P0验收。

## R02：scope不能直接证明存在逐客户数据隔离

**观察。** [ArticleController](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Controllers/Api/V1/ArticleController.php)的普通列表与详情调用没有显式传入viewer；[ArticleGeoFlowService](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Services/GeoFlow/ArticleGeoFlowService.php)的对应方法直接构建文章查询。任务API则有显式viewer参数。仅凭这些入口不能确认整个系统的全局策略或租户隔离，不能据此声称存在已证实的跨租户漏洞。

**完善。** 首版明确授权整实例共享运营资料，或先补齐并验证Core资源策略。涉及多客户时要在Core落实列表过滤、单条访问、关联素材与写入授权，不能只依靠模型传入site_id。

**通过要求。** 同一实例不同账号、不同实例同一资源ID、直接访问详情及关联资源都应按已声明范围验收。范围无法确认时不开放对应工具。

## R03：普通文章更新可能影响已发布内容

**观察。** `ArticleGeoFlowService::updateArticle`处理标题、正文等风险相关字段变化时，会合并draft/pending回退状态。这个流程服务于现有文章管理；它不能直接承担“仅修改草稿”的更窄插件契约。

**风险。** 仅在MCP层先GET检查文章状态，随后PATCH，仍可能在两次请求之间遇到Web操作把文章发布。最终写入可能改变线上文章状态。

**完善。** 新增或收紧Core草稿专用契约，在同一事务中验证资源权限、草稿状态和预期版本。已发布对象必须返回冲突，不能隐式下架。该保护缺失时，P1写入保持关闭。

## R04：质检配置版本不能替代全文并发版本

**观察。** [UpdateArticleRequest](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Requests/Api/UpdateArticleRequest.php)和ArticleController中，config_version用于部分质检配置或任务关联变化的检查。已读普通正文更新路径没有等价的通用预期内容版本校验。业务服务已有事务和行锁，本发现不否定这些现有并发保护。

**完善。** 为草稿操作设计统一revision或等价强前置条件；Web、API和Worker的相关内容修改都更新同一版本。仅有行锁不自动发现“用户看到旧正文后提交的新修改”，仅有MCP内存锁也约束不了其他入口。

**通过要求。** 两个操作者基于同一版本修改，最多一个成功；冲突后重新读取和生成计划，不覆盖他人内容。

## R05：任务已有审核保护，执行快照仍需验证

**观察。** [TaskController](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Controllers/Api/V1/TaskController.php)的reviewBoundTaskData会对缺少articles:publish的Token强制need_review；assertTaskExecutionScope会阻止此类Token启动可自动发布的任务。原方案需要明确保留这些已有防护。

**待验证。** 本轮没有完成从所有任务写入口到Worker的全链路动态测试，不能断言队列执行存在可利用的竞态。

**完善。** P2要求任务计划绑定模型、知识源、提示词、渠道、审核模式及revision；入队原子验证，并由Worker使用获准快照或检测漂移后停止。增加篇数、Token、并发和附加质检费用的预算约束。

## R06：capabilities没有覆盖全部旧业务API

**观察。** [ManagementOperationRegistry](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Support/Api/ManagementOperationRegistry.php)包含管理操作与tasks.enqueue，但没有完整列出旧文章、目录及素材接口。[远程管理覆盖说明](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/docs/api/remote-management-preview.md)也声明嵌套schema未全部完成。

**完善。** 新管理操作依赖服务端声明，旧API使用版本化兼容映射与契约测试。工具列表来自经过审查的白名单，不把Web路由或pending台账自动暴露。schema漂移及未知版本关闭写入。

**通过要求。** 既不能误宣称某个未实现能力可用，也不能只因旧API未在注册表列出就错误地判定其不存在。

## R07：幂等、收据和HTTP状态需要分别解释

**观察。** [IdempotencyService](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Services/Api/IdempotencyService.php)含in_progress、stale和uncertain等处理；任务入队明确拒绝同时传入两种幂等头。[远程CLI规范](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/.agents/skills/geoflow/references/remote-cli-workflow.md)说明恢复后收据缺失不能证明未执行。文章创建流程还可能保留草稿并返回门禁阻断。

**完善。** 逐操作登记幂等和恢复契约；持久化同一业务计划的请求ID；超时后优先查收据与实际资源。HTTP失败不能直接显示“没有任何变化”，HTTP成功也不能直接显示“发布完成”。

**通过要求。** 重试、崩溃、旧Token续接和恢复后的404都必须有确定处理方式，无法确定时保留unknown并停止自动重发。

## R08：一次工具调用可能对应多个后续状态

**观察。** 任务与执行记录通过不同API查询，现有工作流涉及生成、质检、审核和分发。仓库CLI文档明确禁止将pending报告为完成。

**完善。** 将operation_state、work_state和effects_state分开；记录任务、Job、文章及收据的不同ID。输出逐渠道效果，说明尚未完成和已发生的部分副作用。

**通过要求。** 入队成功、收据完成、生成成功、质检通过和远端发布分别核对。首版不承诺会话结束后的主动通知；计划中的定时任务需要单独实现。

## R09：轻量质检状态不能承担证据分析

**观察。** ArticleController的aiQualityStatus注释明确说明轻量响应不包含文章正文、证据正文或供应商错误；getArticle另行返回ai_quality详情。[CatalogController](https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Controllers/Api/V1/CatalogController.php)将当前审计管理员ID交给目录服务。

**完善。** 分开状态工具与详情工具，按用途投影字段。保留Core已有模型可见性规则，不整包转发目录、供应商日志和知识库。未经完整字段复核，不能宣称所有API输出都已脱敏。

**通过要求。** 结果保留来源定位、版本及截断信息，同时不泄露密钥、无关个人数据和后台配置。日志及错误路径执行相同限制。

## R10：最近7天的统计需要真实的时间过滤和覆盖信息

**观察。** 已读文章列表控制器没有通用from/to时间过滤；服务返回分页信息，详情部分时间字段格式为不带时区的字符串。

**完善。** 工具不能发送服务器忽略的过滤参数后声称统计完整。可以只报告受限分页内的样本，显式标记partial；完整统计需补充授权范围内的过滤与聚合，明确时区、as_of及分页期间的一致性策略。

**通过要求。** 跨时区、夏令时、零结果、多页结果和同步变更均有可核对语义。不能把前20篇的统计包装成全站周报。

## R11：撤销、恢复与实例绑定需要形成闭合流程

**观察。** Token服务已有过期、停用账号及恢复相关校验；它们只证明相应Core逻辑存在，不能证明未来OAuth层与MCP缓存会自动同步。

**完善。** 连接绑定主体、实例、账号和grant_version；每次调用重查权限；本地撤销先阻止新调用，再处理远端专属Token。业务数据库恢复后，使旧批准与连接失效，或依赖不会随该恢复回滚的撤销边界。基础地址及必要的身份发现请求实行目的地约束。

**通过要求。** 撤销失败不得显示全部撤销；替换实例、恢复旧库、Token过期及排队工作都需测试。禁止模型参数改变请求主机和凭据。

## R12：插件打包、客户端接入与实施验收不能混为一项

**观察。** [当前打包文档](https://developers.openai.com/plugins/build/plugins)区分portable root manifest与兼容格式；[连接文档](https://developers.openai.com/plugins/deploy/connect-chatgpt)区分公开HTTPS、开发隧道、实际账号策略与工具元数据刷新。

**完善。** 先验收MCP，再验收完整插件与Skill，最后考虑组织内或公开目录分发。新增字段、工具annotations、会话隔离、协议协商、代理路径、SDK锁定、密钥管理和禁用开关。公开示例不包含真实连接ID、秘密或个人客户数据。

**通过要求。** 使用实际ChatGPT账号完成完整安装与授权流程；文档PR只能声明设计完成，不能声明48项未来测试已通过。仓库PR模板中的CLA法律声明由有权主体确认，本次不代为签署或填写法定身份。

## 完善后的实施决策

优先交付P0只读试点。P1补齐Core安全草稿接口，P2增加配置快照和预算，P3才开放可信批准后的发布。高风险主机操作和任意代码继续排除。各阶段都通过对应验收后独立开放，默认关闭未验证能力。

这份报告记录静态复核与设计决策；运行时安全结论、兼容版本范围、生产部署与测试通过情况应由后续实施PR提供独立证据。
