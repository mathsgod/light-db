# TODO

- [ ] 考慮加入保留現有關聯 API 相容性嘅 eager loading（待決定，未實作）。
  - 擬議用法：`User::Query()->with('UserList')->execute()`。
  - `execute()` 保持回傳 `Collection<User>`；批次查詢關聯資料並快取喺各個 model，避免逐筆查詢造成 N+1。
  - 新增 `getRelation('UserList')` 讀取已載入關聯，唔再查 DB。
  - 一對多關聯回傳 `Collection<Model>`，冇資料時回傳空 collection；一對一／多對一回傳 `Model|null`。
  - 保留 `$user->UserList` 回傳 Query，同 `$user->UserList()` 即時查詢嘅現有行為及回傳類型。
  - 實作前再決定關聯定義及 key 對應、未載入時 `getRelation()` 嘅行為、快取刷新方式，以及巢狀關聯支援範圍。
