/**
 * 订单列表页共用实现
 *   isf      ISF 订单
 *   customs  清关订单（28 列）
 *   pickup   提拆派订单（28 列，与清关仅第 3 列 / 第 27 列不同）
 *   inspect  查验订单
 */
(function (w) {
  'use strict';

  var STATUS = [
    { label: '已预报', value: 'forecast' },
    { label: '申报中', value: 'declaring' },
    { label: '查验中', value: 'inspecting' },
    { label: '已放行', value: 'released' }
  ];

  /* createPerm：这个列表页「创建新订单」按钮需要的权限码
     （sys_permission 里 type=2 的按钮节点，见 db/upgrade-2026-09-21b.sql）。
     有权限 → 按钮可点；没权限 → 按钮置灰 + 悬停说明原因，后端 POST /orders 也会再拦一次。 */
  var META = {
    isf: {
      title: 'ISF 订单',
      kind: 'isf',
      filters: ['customer_no', 'match_status', 'declare'],
      createText: '创建 ISF 订单',
      createPerm: 'order.isf.new'
    },
    customs: {
      title: '清关订单',
      kind: 'wide',
      col3: 'importer',
      col27: 'remark',
      filters: ['customer_no', 'mbl_no', 'hbl_no', 'importer_id', 'container_no', 'plan_id', 'customs_status', 'is_verified', 'release', 'created'],
      createText: '创建订单',
      createPerm: 'order.customs.new'
    },
    pickup: {
      title: '提拆派订单',
      kind: 'wide',
      col3: 'container',
      col27: 'customer_remark',
      filters: ['customer_no', 'mbl_no', 'hbl_no', 'container_no', 'importer_id', 'customs_status', 'release', 'created'],
      createText: '创建提拆派订单',
      createPerm: 'order.pickup.new'
    },
    inspect: {
      title: '查验订单',
      kind: 'inspect',
      filters: ['customer_no', 'mbl_no', 'container_no', 'inspect_type', 'customs_status'],
      createText: '创建订单',
      createPerm: 'order.inspect.new'
    }
  };

  /* ============ 列表模板 ============ */
  var TPL_WIDE = `
<div class="page">
  <div class="page-head">
    <el-tooltip content="没有创建订单的权限，请联系管理员开通" :disabled="canCreate" placement="bottom">
      <span><el-button class="btn-green" :disabled="!canCreate" @click="goCreate">{{ meta.createText }}</el-button></span>
    </el-tooltip>
    <div class="spacer"></div>
    <el-button @click="search()">刷新</el-button>
    <el-button @click="exportCsv">导出 Excel</el-button>
  </div>

  <div class="filter-bar">
    <div class="fi"><label>客户单号</label><el-input v-model="q.customer_no" clearable placeholder="客户单号" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>主提单号</label><el-input v-model="q.mbl_no" clearable placeholder="MBL" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>分单号</label><el-input v-model="q.hbl_no" clearable placeholder="HBL" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>柜号</label><el-input v-model="q.container_no" clearable placeholder="柜号" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>进口商</label>
      <el-select v-model="q.importer_id" clearable filterable placeholder="全部">
        <el-option v-for="o in opts.importers" :key="o.id" :label="o.company_name" :value="o.id" />
      </el-select>
    </div>
    <div class="fi"><label>报价方案</label>
      <el-select v-model="q.plan_id" clearable placeholder="全部">
        <el-option v-for="o in opts.plans" :key="o.id" :label="o.plan_name" :value="o.id" />
      </el-select>
    </div>
    <div class="fi"><label>清关状态</label>
      <el-select v-model="q.customs_status" clearable placeholder="全部">
        <el-option v-for="o in statusList" :key="o.value" :label="o.label" :value="o.value" />
      </el-select>
    </div>
    <div class="fi"><label>是否核销</label>
      <el-select v-model="q.is_verified" clearable placeholder="全部">
        <el-option label="未核销" :value="0" /><el-option label="已核销" :value="1" />
      </el-select>
    </div>
    <div class="fi"><label>放行日期</label>
      <el-date-picker v-model="releaseRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" />
    </div>
    <div class="fi"><label>创建时间</label>
      <el-date-picker v-model="createdRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" />
    </div>
    <div class="acts">
      <el-button type="primary" @click="search(true)">查询</el-button>
      <el-button @click="doReset">重置</el-button>
    </div>
  </div>

  <div class="table-card">
    <el-table :data="rows" v-loading="loading" border stripe size="small" :max-height="560">
      <el-table-column type="index" label="#" width="48" fixed align="center" />
      <el-table-column label="提单号" width="130" fixed>
        <template #default="{ row }"><span class="link" @click="openDetail(row)">{{ row.mbl_no || '—' }}</span></template>
      </el-table-column>
      <el-table-column prop="hbl_no" label="分单号" width="120" fixed show-overflow-tooltip />
      <el-table-column v-if="meta.col3 === 'importer'" label="进口商" width="170" show-overflow-tooltip>
        <template #default="{ row }">{{ row.importer_name || '—' }}</template>
      </el-table-column>
      <el-table-column v-else label="柜号" width="150" show-overflow-tooltip>
        <template #default="{ row }">{{ row.container_nos || '—' }}</template>
      </el-table-column>
      <el-table-column label="报价方案" width="120" show-overflow-tooltip>
        <template #default="{ row }">{{ row.plan_name || '—' }}</template>
      </el-table-column>
      <el-table-column label="方案介绍" width="150">
        <template #default="{ row }">
          <el-tooltip v-if="row.plan_intro" :content="row.plan_intro" placement="top" :show-after="300">
            <span class="ellip" style="max-width:132px">{{ row.plan_intro }}</span>
          </el-tooltip>
          <span v-else class="muted">—</span>
        </template>
      </el-table-column>
      <el-table-column label="报价" width="94" align="right">
        <template #default="{ row }">{{ $qg.money(row.plan_amount) }}</template>
      </el-table-column>
      <el-table-column label="实收费用" width="100" align="right">
        <template #default="{ row }">{{ $qg.money(row.actual_fee) }}</template>
      </el-table-column>
      <el-table-column prop="container_type" label="柜型" width="74" />
      <el-table-column label="柜重" width="84" align="right">
        <template #default="{ row }">{{ row.container_weight || '' }}</template>
      </el-table-column>
      <el-table-column label="ETD" width="100">
        <template #default="{ row }">{{ $qg.date(row.etd) }}</template>
      </el-table-column>
      <el-table-column label="ETA" width="100">
        <template #default="{ row }">{{ $qg.date(row.eta) }}</template>
      </el-table-column>
      <el-table-column label="发货人" width="160" show-overflow-tooltip>
        <template #default="{ row }">{{ row.shipper_name || '—' }}</template>
      </el-table-column>
      <el-table-column prop="port_of_loading" label="起运港" width="110" show-overflow-tooltip />
      <el-table-column prop="port_of_delivery" label="目的港" width="110" show-overflow-tooltip />
      <el-table-column prop="customs_port" label="清关港" width="110" show-overflow-tooltip />
      <el-table-column prop="port_of_discharge" label="卸货港" width="110" show-overflow-tooltip />
      <el-table-column prop="vessel" label="船名" width="130" show-overflow-tooltip />
      <el-table-column prop="voyage" label="船次" width="90" show-overflow-tooltip />
      <el-table-column prop="route_type" label="航线类型" width="100" />
      <el-table-column label="清关状态" width="96" align="center">
        <template #default="{ row }">
          <el-tag :type="$qg.statusTag(row.customs_status)" size="small" disable-transitions>{{ row.customs_status_text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="放行日期" width="100">
        <template #default="{ row }">{{ $qg.date(row.release_date) }}</template>
      </el-table-column>
      <el-table-column prop="inspect_type" label="查验类型" width="96" />
      <el-table-column prop="inspect_abnormal" label="查验异常" width="96" />
      <el-table-column label="文件" width="66" align="center">
        <template #default="{ row }">
          <span v-if="row.file_count" class="link" @click="openDetail(row, 'files')">{{ row.file_count }}</span>
          <span v-else class="muted">0</span>
        </template>
      </el-table-column>
      <el-table-column label="创建时间" width="140">
        <template #default="{ row }">{{ $qg.dateTime(row.created_at) }}</template>
      </el-table-column>
      <el-table-column label="是否核销" width="90" align="center">
        <template #default="{ row }">
          <el-tag :type="Number(row.is_verified) === 1 ? 'success' : 'info'" size="small" disable-transitions>{{ row.is_verified_text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column v-if="meta.col27 === 'remark'" prop="remark" label="备注" width="150" show-overflow-tooltip />
      <el-table-column v-else prop="customer_remark" label="客户备注" width="150" show-overflow-tooltip />
      <el-table-column label="操作" width="150" fixed="right" align="center">
        <template #default="{ row }">
          <span class="link" @click="openDetail(row)">详情</span>
          <el-divider direction="vertical" />
          <el-dropdown trigger="click" @command="c => rowCmd(c, row)">
            <span class="link">更多<el-icon><ArrowDown /></el-icon></span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="status">变更清关状态</el-dropdown-item>
                <el-dropdown-item command="verify">{{ Number(row.is_verified) === 1 ? '取消核销' : '标记核销' }}</el-dropdown-item>
                <el-dropdown-item command="edit">编辑订单</el-dropdown-item>
                <el-dropdown-item command="isf">登记 ISF 申报</el-dropdown-item>
                <el-dropdown-item command="inspect">登记查验记录</el-dropdown-item>
                <el-dropdown-item command="log">操作日志</el-dropdown-item>
                <el-dropdown-item command="del" divided>删除订单</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </template>
      </el-table-column>
    </el-table>

    <div style="display:flex;justify-content:flex-end;margin-top:12px">
      <el-pagination v-model:current-page="q.page" v-model:page-size="q.pageSize" :total="total"
        :page-sizes="[20,50,100,200]" background layout="total, sizes, prev, pager, next, jumper"
        @current-change="pageChange" @size-change="sizeChange" />
    </div>
  </div>

  <order-detail :show="detailVisible" :id="detailId" :tab="detailTab" @close="detailVisible = false" />

  <el-dialog v-model="statusVisible" title="变更清关状态" width="440px">
    <el-form label-width="90px">
      <el-form-item label="订单"><b>{{ cur.mbl_no }}</b> <span class="muted">{{ cur.importer_name || '' }}</span></el-form-item>
      <el-form-item label="当前状态">
        <el-tag :type="$qg.statusTag(cur.customs_status)" size="small" disable-transitions>{{ cur.customs_status_text }}</el-tag>
      </el-form-item>
      <el-form-item label="新状态">
        <el-select v-model="stForm.customs_status" style="width:100%">
          <el-option v-for="o in statusList" :key="o.value" :label="o.label" :value="o.value" />
        </el-select>
      </el-form-item>
      <el-form-item label="放行日期" v-if="stForm.customs_status === 'released'">
        <el-date-picker v-model="stForm.release_date" type="date" value-format="YYYY-MM-DD" placeholder="默认取今天" style="width:100%" />
      </el-form-item>
      <el-form-item label="备注"><el-input v-model="stForm.remark" type="textarea" :rows="2" placeholder="变更说明（可选）" /></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="statusVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submitStatus">确定</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="isfVisible" title="登记 ISF 申报" width="440px">
    <el-form label-width="90px">
      <el-form-item label="订单"><b>{{ cur.mbl_no }}</b></el-form-item>
      <el-form-item label="匹配结果">
        <el-radio-group v-model="isfForm.status">
          <el-radio :value="1">ISP 匹配成功</el-radio><el-radio :value="0">未匹配</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="匹配信息"><el-input v-model="isfForm.match_msg" placeholder="可选" /></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="isfVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submitIsf">确定</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="inspectVisible" title="登记查验记录" width="520px">
    <el-form label-width="90px">
      <el-form-item label="订单"><b>{{ cur.mbl_no }}</b></el-form-item>
      <el-form-item label="查验类型">
        <el-select v-model="insForm.inspect_type" placeholder="请选择" style="width:100%">
          <el-option v-for="o in dictInspectType" :key="o.value" :label="o.label" :value="o.value" />
        </el-select>
      </el-form-item>
      <el-form-item label="查验日期">
        <el-date-picker v-model="insForm.inspect_date" type="date" value-format="YYYY-MM-DD" style="width:100%" />
      </el-form-item>
      <el-form-item label="是否异常">
        <el-radio-group v-model="insForm.abnormal">
          <el-radio value="无异常">无异常</el-radio><el-radio value="有异常">有异常</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="查验费用"><el-input v-model="insForm.fee" placeholder="0.00" /></el-form-item>
      <el-form-item label="查验结果"><el-input v-model="insForm.result" type="textarea" :rows="3" /></el-form-item>
      <el-form-item label="备注"><el-input v-model="insForm.remark" type="textarea" :rows="2" /></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="inspectVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submitInspect">确定</el-button>
    </template>
  </el-dialog>
</div>`;

  var TPL_ISF = `
<div class="page">
  <div class="page-head">
    <el-tooltip content="没有创建订单的权限，请联系管理员开通" :disabled="canCreate" placement="bottom">
      <span><el-button class="btn-green" :disabled="!canCreate" @click="goCreate">{{ meta.createText }}</el-button></span>
    </el-tooltip>
    <div class="spacer"></div>
    <el-button @click="search()">刷新</el-button>
    <el-button @click="exportCsv">导出 Excel</el-button>
  </div>

  <div class="filter-bar">
    <div class="fi"><label>客户单号</label><el-input v-model="q.customer_no" clearable placeholder="客户单号" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>主提单号</label><el-input v-model="q.mbl_no" clearable placeholder="MBL" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>分单号</label><el-input v-model="q.hbl_no" clearable placeholder="HBL" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>匹配状态</label>
      <el-select v-model="q.match_status" clearable placeholder="全部">
        <el-option label="未匹配" :value="0" /><el-option label="ISP匹配成功" :value="1" />
      </el-select>
    </div>
    <div class="fi"><label>申报日期</label>
      <el-date-picker v-model="declareRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" />
    </div>
    <div class="acts">
      <el-button type="primary" @click="search(true)">查询</el-button>
      <el-button @click="doReset">重置</el-button>
    </div>
  </div>

  <div class="table-card">
    <el-table :data="rows" v-loading="loading" border stripe size="small" :max-height="620">
      <el-table-column type="index" label="#" width="52" align="center" />
      <el-table-column label="主提单号" min-width="150">
        <template #default="{ row }"><span class="link" @click="openDetail(row)">{{ row.mbl_no || '—' }}</span></template>
      </el-table-column>
      <el-table-column prop="hbl_no" label="分单号" min-width="140" show-overflow-tooltip />
      <el-table-column label="进口商" min-width="200" show-overflow-tooltip>
        <template #default="{ row }">{{ row.importer_name || '—' }}</template>
      </el-table-column>
      <el-table-column label="申报日期" width="150">
        <template #default="{ row }">{{ $qg.dateTime(row.declare_date) }}</template>
      </el-table-column>
      <el-table-column label="ETD" width="110">
        <template #default="{ row }">{{ $qg.date(row.etd) }}</template>
      </el-table-column>
      <el-table-column label="匹配状态" width="130" align="center">
        <template #default="{ row }">
          <el-tag :type="Number(row.match_status) === 1 ? 'success' : 'info'" size="small" disable-transitions>{{ row.match_status_text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="customer_remark" label="客户备注" min-width="160" show-overflow-tooltip />
      <el-table-column label="操作" width="170" align="center" fixed="right">
        <template #default="{ row }">
          <span class="link" @click="openDetail(row)">详情</span>
          <el-divider direction="vertical" />
          <span class="link" @click="openLog(row)">日志</span>
          <el-divider direction="vertical" />
          <span class="link" @click="openIsf(row)">登记</span>
        </template>
      </el-table-column>
    </el-table>

    <div style="display:flex;justify-content:flex-end;margin-top:12px">
      <el-pagination v-model:current-page="q.page" v-model:page-size="q.pageSize" :total="total"
        :page-sizes="[20,50,100,200]" background layout="total, sizes, prev, pager, next, jumper"
        @current-change="pageChange" @size-change="sizeChange" />
    </div>
  </div>

  <order-detail :show="detailVisible" :id="detailId" :tab="detailTab" @close="detailVisible = false" />

  <el-dialog v-model="isfVisible" title="登记 ISF 申报" width="440px">
    <el-form label-width="90px">
      <el-form-item label="订单"><b>{{ cur.mbl_no }}</b></el-form-item>
      <el-form-item label="匹配结果">
        <el-radio-group v-model="isfForm.status">
          <el-radio :value="1">ISP 匹配成功</el-radio><el-radio :value="0">未匹配</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="匹配信息"><el-input v-model="isfForm.match_msg" placeholder="可选" /></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="isfVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submitIsf">确定</el-button>
    </template>
  </el-dialog>
</div>`;

  var TPL_INSPECT = `
<div class="page">
  <div class="page-head">
    <el-tooltip content="没有创建订单的权限，请联系管理员开通" :disabled="canCreate" placement="bottom">
      <span><el-button class="btn-green" :disabled="!canCreate" @click="goCreate">{{ meta.createText }}</el-button></span>
    </el-tooltip>
    <div class="spacer"></div>
    <el-button @click="search()">刷新</el-button>
  </div>

  <div class="filter-bar">
    <div class="fi"><label>客户单号</label><el-input v-model="q.customer_no" clearable placeholder="客户单号" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>主提单号</label><el-input v-model="q.mbl_no" clearable placeholder="MBL" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>柜号</label><el-input v-model="q.container_no" clearable placeholder="柜号" @keyup.enter="search(true)" /></div>
    <div class="fi"><label>查验类型</label>
      <el-select v-model="q.inspect_type" clearable placeholder="全部">
        <el-option v-for="o in dictInspectType" :key="o.value" :label="o.label" :value="o.value" />
      </el-select>
    </div>
    <div class="fi"><label>清关状态</label>
      <el-select v-model="q.customs_status" clearable placeholder="全部">
        <el-option v-for="o in statusList" :key="o.value" :label="o.label" :value="o.value" />
      </el-select>
    </div>
    <div class="acts">
      <el-button type="primary" @click="search(true)">查询</el-button>
      <el-button @click="doReset">重置</el-button>
    </div>
  </div>

  <div class="table-card">
    <el-table :data="rows" v-loading="loading" border stripe size="small" :max-height="620">
      <el-table-column type="index" label="#" width="52" align="center" />
      <el-table-column label="主提单号" min-width="150">
        <template #default="{ row }"><span class="link" @click="openDetail(row)">{{ row.mbl_no || '—' }}</span></template>
      </el-table-column>
      <el-table-column prop="hbl_no" label="分单号" min-width="130" show-overflow-tooltip />
      <el-table-column label="柜号" min-width="150" show-overflow-tooltip>
        <template #default="{ row }">{{ row.container_nos || '—' }}</template>
      </el-table-column>
      <el-table-column label="进口商" min-width="190" show-overflow-tooltip>
        <template #default="{ row }">{{ row.importer_name || '—' }}</template>
      </el-table-column>
      <el-table-column label="清关状态" width="100" align="center">
        <template #default="{ row }">
          <el-tag :type="$qg.statusTag(row.customs_status)" size="small" disable-transitions>{{ row.customs_status_text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="inspect_type" label="查验类型" width="110" />
      <el-table-column prop="inspect_abnormal" label="查验异常" width="100" />
      <el-table-column label="放行日期" width="110">
        <template #default="{ row }">{{ $qg.date(row.release_date) }}</template>
      </el-table-column>
      <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />
      <el-table-column label="操作" width="150" align="center" fixed="right">
        <template #default="{ row }">
          <span class="link" @click="openDetail(row)">详情</span>
          <el-divider direction="vertical" />
          <span class="link" @click="openInspect(row)">登记查验</span>
        </template>
      </el-table-column>
    </el-table>

    <div style="display:flex;justify-content:flex-end;margin-top:12px">
      <el-pagination v-model:current-page="q.page" v-model:page-size="q.pageSize" :total="total"
        :page-sizes="[20,50,100,200]" background layout="total, sizes, prev, pager, next, jumper"
        @current-change="pageChange" @size-change="sizeChange" />
    </div>
  </div>

  <order-detail :show="detailVisible" :id="detailId" :tab="detailTab" @close="detailVisible = false" />

  <el-dialog v-model="inspectVisible" title="登记查验记录" width="520px">
    <el-form label-width="90px">
      <el-form-item label="订单"><b>{{ cur.mbl_no }}</b></el-form-item>
      <el-form-item label="查验类型">
        <el-select v-model="insForm.inspect_type" placeholder="请选择" style="width:100%">
          <el-option v-for="o in dictInspectType" :key="o.value" :label="o.label" :value="o.value" />
        </el-select>
      </el-form-item>
      <el-form-item label="查验日期">
        <el-date-picker v-model="insForm.inspect_date" type="date" value-format="YYYY-MM-DD" style="width:100%" />
      </el-form-item>
      <el-form-item label="是否异常">
        <el-radio-group v-model="insForm.abnormal">
          <el-radio value="无异常">无异常</el-radio><el-radio value="有异常">有异常</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="查验费用"><el-input v-model="insForm.fee" placeholder="0.00" /></el-form-item>
      <el-form-item label="查验结果"><el-input v-model="insForm.result" type="textarea" :rows="3" /></el-form-item>
      <el-form-item label="备注"><el-input v-model="insForm.remark" type="textarea" :rows="2" /></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="inspectVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submitInspect">确定</el-button>
    </template>
  </el-dialog>
</div>`;

  /* ============ 详情抽屉组件 ============ */
  var DETAIL = {
    name: 'OrderDetail',
    props: { show: Boolean, id: [Number, String], tab: String },
    emits: ['close'],
    data: function () {
      return { loading: false, d: {}, active: 'basic' };
    },
    watch: {
      show: function (v) {
        if (v && this.id) { this.active = this.tab || 'basic'; this.load(); }
      }
    },
    methods: {
      load: function () {
        var self = this;
        self.loading = true;
        QG.get('/orders/' + self.id).then(function (d) { self.d = d || {}; })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.loading = false; });
      },
      close: function () { this.$emit('close'); }
    },
    template: `
<el-drawer :model-value="show" size="66%" :title="'订单详情 · ' + (d.mbl_no || '')" @update:model-value="close" destroy-on-close>
  <div v-loading="loading">
    <el-tabs v-model="active">
      <el-tab-pane label="基本信息" name="basic">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="系统单号">{{ d.order_no }}</el-descriptions-item>
          <el-descriptions-item label="客户单号">{{ d.customer_no || '—' }}</el-descriptions-item>
          <el-descriptions-item label="客户订单号">{{ d.customer_order_no || '—' }}</el-descriptions-item>
          <el-descriptions-item label="主提单号/MBL">{{ d.mbl_no }}</el-descriptions-item>
          <el-descriptions-item label="分单号/HBL">{{ d.hbl_no || '—' }}</el-descriptions-item>
          <el-descriptions-item label="船公司">{{ d.carrier || '—' }}</el-descriptions-item>
          <el-descriptions-item label="进口商/BOND">{{ d.importer_name || '—' }}</el-descriptions-item>
          <el-descriptions-item label="报价方案">{{ d.plan_name || '—' }}</el-descriptions-item>
          <el-descriptions-item label="船名">{{ d.vessel || '—' }}</el-descriptions-item>
          <el-descriptions-item label="船次">{{ d.voyage || '—' }}</el-descriptions-item>
          <el-descriptions-item label="原产国">{{ d.origin_country || '—' }}</el-descriptions-item>
          <el-descriptions-item label="目的国家">{{ d.dest_country || '—' }}</el-descriptions-item>
          <el-descriptions-item label="始发港">{{ d.port_of_loading || '—' }}</el-descriptions-item>
          <el-descriptions-item label="目的港">{{ d.port_of_delivery || '—' }}</el-descriptions-item>
          <el-descriptions-item label="卸货港">{{ d.port_of_discharge || '—' }}</el-descriptions-item>
          <el-descriptions-item label="清关港">{{ d.customs_port || '—' }}</el-descriptions-item>
          <el-descriptions-item label="ETD">{{ $qg.date(d.etd) || '—' }}</el-descriptions-item>
          <el-descriptions-item label="ETA">{{ $qg.date(d.eta) || '—' }}</el-descriptions-item>
          <el-descriptions-item label="报价">{{ $qg.money(d.plan_amount) }}</el-descriptions-item>
          <el-descriptions-item label="实收费用">{{ $qg.money(d.actual_fee) }}</el-descriptions-item>
          <el-descriptions-item label="柜型">{{ d.container_type || '—' }}</el-descriptions-item>
          <el-descriptions-item label="柜重">{{ d.container_weight || '—' }}</el-descriptions-item>
          <el-descriptions-item label="清关状态">
            <el-tag :type="$qg.statusTag(d.customs_status)" size="small" disable-transitions>{{ d.customs_status_text }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="放行日期">{{ $qg.date(d.release_date) || '—' }}</el-descriptions-item>
          <el-descriptions-item label="是否核销">{{ d.is_verified_text }}</el-descriptions-item>
          <el-descriptions-item label="匹配状态">{{ d.match_status_text }}</el-descriptions-item>
          <el-descriptions-item label="航线类型">{{ d.route_type || '—' }}</el-descriptions-item>
          <el-descriptions-item label="业务类型">{{ d.biz_type || '—' }}</el-descriptions-item>
          <el-descriptions-item label="查验类型">{{ d.inspect_type || '—' }}</el-descriptions-item>
          <el-descriptions-item label="查验异常">{{ d.inspect_abnormal || '—' }}</el-descriptions-item>
          <el-descriptions-item label="创建人">{{ d.creator_name || '—' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ $qg.dateTime(d.created_at) }}</el-descriptions-item>
          <el-descriptions-item label="客户备注" :span="2">{{ d.customer_remark || '—' }}</el-descriptions-item>
          <el-descriptions-item label="备注" :span="2">{{ d.remark || '—' }}</el-descriptions-item>
        </el-descriptions>
      </el-tab-pane>

      <el-tab-pane :label="'柜子信息 (' + (d.containers || []).length + ')'" name="containers">
        <el-table :data="d.containers || []" border size="small">
          <el-table-column type="index" label="#" width="52" />
          <el-table-column prop="container_no" label="柜号" min-width="150" />
          <el-table-column prop="container_type" label="柜型" width="90" />
          <el-table-column prop="gross_weight" label="柜重" width="100" />
          <el-table-column prop="packages" label="箱数" width="90" />
          <el-table-column prop="cbm" label="方数" width="90" />
        </el-table>
      </el-tab-pane>

      <el-tab-pane :label="'HS CODE (' + (d.hscodes || []).length + ')'" name="hscodes">
        <el-table :data="d.hscodes || []" border size="small">
          <el-table-column type="index" label="#" width="52" />
          <el-table-column prop="hs_code" label="HS CODE" min-width="180" />
          <el-table-column prop="origin_country" label="原产国" width="140" />
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="抬头信息" name="addresses">
        <div class="addr-grid">
          <div class="addr-card" v-for="a in (d.addresses || [])" :key="a.id">
            <h4>{{ {1:'Shipper 发货人',2:'Consignee 收货人',3:'Notify party 通知方'}[a.addr_type] }}</h4>
            <el-descriptions :column="1" size="small">
              <el-descriptions-item label="公司">{{ a.company || '—' }}</el-descriptions-item>
              <el-descriptions-item label="国家">{{ a.country || '—' }}</el-descriptions-item>
              <el-descriptions-item label="州/省">{{ a.state_province || '—' }}</el-descriptions-item>
              <el-descriptions-item label="城市">{{ a.city || '—' }}</el-descriptions-item>
              <el-descriptions-item label="邮编">{{ a.zip_code || '—' }}</el-descriptions-item>
              <el-descriptions-item label="地址">{{ a.address || '—' }}</el-descriptions-item>
              <el-descriptions-item label="电话">{{ a.phone || '—' }}</el-descriptions-item>
            </el-descriptions>
          </div>
        </div>
        <div class="empty-holder" v-if="!(d.addresses || []).length">暂无抬头信息</div>
      </el-tab-pane>

      <el-tab-pane :label="'附件 (' + (d.files || []).length + ')'" name="files">
        <el-table :data="d.files || []" border size="small">
          <el-table-column prop="file_type" label="类型" width="120" />
          <el-table-column label="文件名" min-width="240">
            <template #default="{ row }"><a :href="$qg.uploadUrl(row)" target="_blank">{{ row.file_name }}</a></template>
          </el-table-column>
          <el-table-column label="大小" width="110">
            <template #default="{ row }">{{ $qg.fileSize(row.file_size) }}</template>
          </el-table-column>
          <el-table-column label="上传时间" width="150">
            <template #default="{ row }">{{ $qg.dateTime(row.created_at) }}</template>
          </el-table-column>
        </el-table>
        <div class="empty-holder" v-if="!(d.files || []).length">暂无附件</div>
      </el-tab-pane>

      <el-tab-pane :label="'ISF 记录 (' + (d.isfs || []).length + ')'" name="isfs">
        <el-table :data="d.isfs || []" border size="small">
          <el-table-column type="index" label="#" width="52" />
          <el-table-column label="申报日期" width="170">
            <template #default="{ row }">{{ $qg.dateTime(row.declare_date) }}</template>
          </el-table-column>
          <el-table-column label="匹配结果" width="140">
            <template #default="{ row }">
              <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'" size="small" disable-transitions>{{ Number(row.status) === 1 ? 'ISP匹配成功' : '未匹配' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="match_msg" label="匹配信息" min-width="180" />
        </el-table>
        <div class="empty-holder" v-if="!(d.isfs || []).length">暂无 ISF 记录</div>
      </el-tab-pane>

      <el-tab-pane :label="'查验记录 (' + (d.inspects || []).length + ')'" name="inspects">
        <el-table :data="d.inspects || []" border size="small">
          <el-table-column type="index" label="#" width="52" />
          <el-table-column prop="inspect_type" label="查验类型" width="120" />
          <el-table-column label="查验日期" width="150">
            <template #default="{ row }">{{ $qg.date(row.inspect_date) }}</template>
          </el-table-column>
          <el-table-column prop="abnormal" label="是否异常" width="100" />
          <el-table-column prop="result" label="查验结果" min-width="180" show-overflow-tooltip />
          <el-table-column label="费用" width="100" align="right">
            <template #default="{ row }">{{ $qg.money(row.fee) }}</template>
          </el-table-column>
        </el-table>
        <div class="empty-holder" v-if="!(d.inspects || []).length">暂无查验记录</div>
      </el-tab-pane>

      <el-tab-pane label="操作日志" name="logs">
        <el-timeline>
          <el-timeline-item v-for="l in (d.logs || [])" :key="l.id" :timestamp="$qg.dateTime(l.created_at)" placement="top">
            <div><b>{{ l.action }}</b> <span class="muted">by {{ l.operator_name || '系统' }}</span></div>
            <div class="muted" style="font-size:12.5px">
              <span v-if="l.field">{{ l.field }}：{{ l.old_value }} → {{ l.new_value }}　</span>{{ l.remark }}
            </div>
          </el-timeline-item>
        </el-timeline>
        <div class="empty-holder" v-if="!(d.logs || []).length">暂无日志</div>
      </el-tab-pane>
    </el-tabs>
  </div>
</el-drawer>`
  };

  /* ============ 生成页面 ============ */
  QG.orderListPage = function (module) {
    var meta = META[module] || META.customs;
    var base = QG.listPage({
      base: '/orders',
      query: { module: module, sortBy: 'o.created_at', sortDir: 'desc' }
    });

    var tpl = meta.kind === 'isf' ? TPL_ISF : (meta.kind === 'inspect' ? TPL_INSPECT : TPL_WIDE);

    return Object.assign({}, base, {
      components: { OrderDetail: DETAIL },
      template: tpl,
      data: function () {
        var d = base.data.call(this);
        d.meta = meta;
        /* 本页「创建新订单」按钮能不能点。默认 false（保守：权限拉回来之前先置灰），
           created 里拿到 /auth/me 之后再放开 —— 见下面 created 的注释。 */
        d.canCreate = false;
        d.statusList = STATUS;
        d.declareRange = [];
        d.releaseRange = [];
        d.createdRange = [];
        d.detailVisible = false;
        d.detailId = null;
        d.detailTab = 'basic';
        d.statusVisible = false;
        d.isfVisible = false;
        d.inspectVisible = false;
        d.cur = {};
        d.stForm = { customs_status: 'forecast', release_date: '', remark: '' };
        d.isfForm = { status: 1, match_msg: '' };
        d.insForm = { inspect_type: '', inspect_date: '', abnormal: '无异常', fee: '', result: '', remark: '' };
        return d;
      },
      /* ⚠️ QG.can() 读的是**本 iframe 自己的** window.QG_USER，而 QG.mount 不会自动加载它
         （listPage.created 里那句 QG.me() 只为预热缓存），所以权限必须异步取 ——
         在 data() 阶段直接 QG.can() 会一律返回 false，把所有人的按钮都变灰。 */
      created: function () {
        base.created.call(this);
        var self = this;
        QG.me().then(function () { self.canCreate = QG.can(meta.createPerm); }).catch(function () {});
      },
      computed: {
        dictInspectType: function () { return this.qgDict('inspect_type'); }
      },
      watch: {
        releaseRange: function (v) { this.q.release_start = v && v[0] || ''; this.q.release_end = v && v[1] || ''; },
        createdRange: function (v) { this.q.created_start = v && v[0] || ''; this.q.created_end = v && v[1] || ''; },
        declareRange: function (v) { this.q.declare_start = v && v[0] || ''; this.q.declare_end = v && v[1] || ''; }
      },
      methods: Object.assign({}, base.methods, {
        qgDict: function (type) { return QG.dictList(type); },
        doReset: function () {
          this.declareRange = []; this.releaseRange = []; this.createdRange = [];
          this.reset();
        },
        /* 跳建单页并带上来源模块（建单页按来源判定该用哪个「创建」权限）；
           这里再兜一次权限校验，防止绕过按钮直接调用。 */
        goCreate: function () {
          if (!this.canCreate) { return QG.warn('没有创建订单的权限，请联系管理员开通'); }
          location.href = 'order-create.html?from=' + module;
        },
        openDetail: function (row, tab) {
          this.detailId = row.id;
          this.detailTab = tab || 'basic';
          this.detailVisible = true;
        },
        openLog: function (row) { this.openDetail(row, 'logs'); },

        /** 登记 ISF 申报（列表行内「登记」与下拉菜单共用） */
        openIsf: function (row) {
          this.cur = row;
          this.isfForm = { status: 1, match_msg: '' };
          this.isfVisible = true;
        },
        /** 登记查验记录（列表行内「登记查验」与下拉菜单共用） */
        openInspect: function (row) {
          this.cur = row;
          this.insForm = { inspect_type: '', inspect_date: '', abnormal: '无异常', fee: '', result: '', remark: '' };
          this.inspectVisible = true;
        },

        rowCmd: function (cmd, row) {
          var self = this;
          self.cur = row;
          if (cmd === 'status') {
            self.stForm = { customs_status: row.customs_status, release_date: '', remark: '' };
            self.statusVisible = true;
          } else if (cmd === 'verify') {
            var next = Number(row.is_verified) === 1 ? 0 : 1;
            QG.confirm(next ? '确认把该订单标记为「已核销」？' : '确认取消该订单的核销标记？', '核销确认').then(function () {
              return QG.post('/orders/' + row.id + '/verify', { is_verified: next }).then(function () {
                QG.toast(next ? '已核销' : '已取消核销');
                self.search();
              });
            }).catch(function (e) { if (e && e.message && e.message !== 'cancel') QG.err(e); });
          } else if (cmd === 'edit') {
            location.href = 'order-create.html?id=' + row.id;
          } else if (cmd === 'isf') {
            self.openIsf(row);
          } else if (cmd === 'inspect') {
            self.openInspect(row);
          } else if (cmd === 'log') {
            self.openDetail(row, 'logs');
          } else if (cmd === 'del') {
            self.remove(row, '', '确定删除订单 ' + (row.mbl_no || '') + ' 吗？删除后不可恢复。');
          }
        },

        submitStatus: function () {
          var self = this;
          if (!self.stForm.customs_status) return QG.warn('请选择状态');
          self.saving = true;
          QG.post('/orders/' + self.cur.id + '/status', self.stForm)
            .then(function () { QG.toast('状态已更新'); self.statusVisible = false; self.search(); })
            .catch(function (e) { QG.err(e); })
            .finally(function () { self.saving = false; });
        },
        submitIsf: function () {
          var self = this;
          self.saving = true;
          QG.post('/orders/' + self.cur.id + '/isf', self.isfForm)
            .then(function () { QG.toast('ISF 记录已登记'); self.isfVisible = false; self.search(); })
            .catch(function (e) { QG.err(e); })
            .finally(function () { self.saving = false; });
        },
        submitInspect: function () {
          var self = this;
          if (!self.insForm.inspect_type) return QG.warn('请选择查验类型');
          self.saving = true;
          QG.post('/orders/' + self.cur.id + '/inspect', self.insForm)
            .then(function () { QG.toast('查验记录已登记'); self.inspectVisible = false; self.search(); })
            .catch(function (e) { QG.err(e); })
            .finally(function () { self.saving = false; });
        },

        exportCsv: function () {
          var parts = [];
          Object.keys(this.q).forEach(function (k) {
            var v = this.q[k];
            if (v !== '' && v !== null && v !== undefined) parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
          }, this);
          window.open('/api/orders/export?' + parts.join('&'), '_blank');
        }
      })
    });
  };

  /* 附件访问地址 */
  QG.uploadUrl = function (row) {
    if (row.url) return row.url;
    return '/uploads/' + String(row.file_path || '').replace(/^\/+/, '');
  };
})(window);
