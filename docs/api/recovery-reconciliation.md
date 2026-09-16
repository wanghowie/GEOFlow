# 恢复后的业务对账边界

当前 Core 提供隔离清单检查。完整恢复完成后，`http_ready` 保持业务变更、自动重试、worker 和 scheduler 暂停。逐条持久决策、解封证明、永久重放隔离和新执行适配仍需实现；此文档不声明 B 批完成。

## 固定只读检查

由宿主机受限执行器在已隔离的新版 Core 中调用：

```text
php artisan geoflow:recovery --phase=reconcile-inspect --transaction=原恢复事务ID --after=0 --limit=100 --json
```

仅接受当前 `validating` 或 `http_ready` 的原事务。`after` 是上一页返回的隔离记录 ID，`limit` 限制为 1 至 200。每页都会重新核对整个来源集合，分页不会降低完整性检查。

输出包含 host、instance、epoch、transaction、准备清单摘要、记录总数和分页记录。每条记录仅包含原表名、ID、完整行摘要、原准备步骤保存的有限业务身份/状态，以及 `replay_adapter_required` 原因。不会输出队列 payload、密码或渠道凭据。

`status=pass` 表示来源仍与准备清单一致。输出始终为 `proof_scope=core_only`、`background_status=held`，包括空清单。它不改变任何原任务，不创建新执行，也不构成开放后台的证明。来源恢复点由宿主机原事务关联，并独立核对不可变清单。

## 全部来源均保留

清单使用 `RecoveryPreparation::INTENTS` 的同一固定目录，保存所有状态的记录。完成记录也可能关联尚未执行的后继工作。

| 表 | 主要续接/重执行入口及身份 |
| --- | --- |
| jobs、failed_jobs、job_batches | 数据库队列 pop、失败 retry、批次后继；原 job/batch ID，payload 仅保留原表 |
| tasks、task_runs | `GeoFlowScheduleTasksCommand`、`JobQueueService` 的调度、claim、补投与重试；task/run ID、schedule_enabled、next_run_at、next_publish_at |
| article_distributions | 分发 worker、渠道重试、HostedSiteReconciler；article/channel、idempotency_key、remote_id；queued/sending/outcome_unknown 等状态不能自动重放 |
| manual_publications | 浏览器 claim/receipt、人工恢复 ready；原 publication、任务/文章、账户与认领记录 |
| site_theme_replications | 主题抓取、生成、迭代、发布工作；原 replication 及目标主题身份 |
| ai_workspace_runs、ai_workspace_steps、ai_workspace_external_operations | 工作区恢复器与外部动作；原 run/step/operation、工具幂等身份及外部结果证据 |
| url_import_jobs | `UrlImportRecoveryService` 的 queued/running 恢复；原 job 及已导入业务对象 |
| article_ai_quality_checks | 检查 worker 与 `ArticleAiQualityReconciliationService`；queued/running 和完成后的发布门禁后继 |
| article_ai_optimization_runs | 优化恢复器；awaiting_quality/queued/planning/rewriting/validating/evaluating/candidate_ready/applying 及自动应用后继 |
| title_generation_runs | `TitleGenerationCoordinator`；queued/running、部分失败的显式重新执行 |
| knowledge_fact_generation_runs | `KnowledgeFactGenerationRecoveryService`；queued/running、具备可恢复批次的终态 |
| ai_visibility_runs | 可见度查询任务；queued/running 及原平台/问题身份 |
| enterprise_knowledge_projects | `EnterpriseKnowledgeDraftRecoveryService`；queued/processing 草稿生成 |
| knowledge_bases | 知识索引恢复；chunk_sync_status pending/processing、chunk_sync_token、来源 hash |
| url_change_requests | `RecoverUrlChanges`；checking/ready/applied/refreshing 及仍需刷新后的路径变更 |
| hosted_site_allocation_requests | `HostedSiteReconciler`；pending、next_attempt_at、分配请求身份 |
| hosted_site_article_assignments | 同一协调器的 reserved 超时与分发后继；文章/站点/分配身份 |

未知队列类型和不支持的领域动作继续暂停。对账不得清空 Redis、删除原行或把所有旧状态改为完成。

## HTTP 读取清单

`http_ready` 仅开放明确列出的站点页面/资产、认证入口、系统更新状态及续接控制，以及已核验的管理 API 读取动作。GET 和 HEAD 本身不代表没有业务写入；未列出的路由保持暂停。

例如知识库事实页 GET 会 `firstOrCreate` 事实库，因此恢复待对账时必须拒绝。新增读取接口需要核验控制器和调用链，再加入清单。站点文章读取继续关闭浏览统计写入。

## 后续逐项恢复的必要条件

每条待恢复工作必须绑定 `(epoch, source_table, source_id, source_sha256)`、来源恢复点、审核者和证据摘要。只有外部查询/幂等记录证明安全，或用户明确确认重执行，才能创建单独的新执行记录。

原记录永久保持重放隔离，所有 scheduler、retry、claim、手动入队、队列 worker 和完成后继均需检查。新执行链接原隔离身份与决定，重新验证当前权限、渠道和业务状态，并使用新的执行 ID。未知结果不能通过换 ID 自动重发。

尚未实现上述全部适配前，不得把 host phase 改为 ready 当作对账完成。未来的空清单证明也只覆盖 Core；宿主机仍须核验恢复点、Redis 隔离 namespace、新生产队列和旧容器退出，并通过受保护的固定流程开放。

旧签名 Core 3.1.0 不提供本命令或新协议。受限恢复 adapter 保持认证失效、后台隔离和健康核验边界；没有安全解封证明时继续限制运行。
