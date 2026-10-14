/**
 * 抬头管理（发货人 / 进口商）共用实现
 */
(function (w) {
  'use strict';

  var TPL = `
<div class="page">
  <div class="page-head">
    <el-button class="btn-green" @click="openCreate()">新增{{ title }}</el-button>
    <div class="spacer"></div>
    <el-button @click="search()">刷新</el-button>
  </div>

  <div class="filter-bar">
    <div class="fi"><label>关键字</label>
      <el-input v-model="q.keyword" clearable placeholder="公司名 / 城市 / 地址" @keyup.enter="search(true)" />
    </div>
    <div class="fi"><label>状态</label>
      <el-select v-model="q.status" clearable placeholder="全部">
        <el-option label="已启用" :value="1" /><el-option label="已停用" :value="0" />
      </el-select>
    </div>
    <div class="acts">
      <el-button type="primary" @click="search(true)">查询</el-button>
      <el-button @click="reset">重置</el-button>
    </div>
  </div>

  <div class="table-card">
    <el-table :data="rows" v-loading="loading" border stripe size="small" :max-height="620">
      <el-table-column type="index" label="#" width="52" align="center" />
      <el-table-column prop="company_name" label="公司" min-width="230" show-overflow-tooltip />
      <el-table-column prop="country" label="国家" width="80" />
      <el-table-column prop="state_province" label="州 / 省" width="110" />
      <el-table-column prop="city" label="城市" width="120" show-overflow-tooltip />
      <el-table-column prop="address" label="地址" min-width="200" show-overflow-tooltip />
      <el-table-column prop="zip_code" label="邮编" width="90" />
      <template v-if="isImporter">
        <el-table-column label="BOND" width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.bond_no || '—' }}</template>
        </el-table-column>
      </template>
      <el-table-column label="创建时间" width="150">
        <template #default="{ row }">{{ $qg.dateTime(row.created_at) }}</template>
      </el-table-column>

      <template v-if="isImporter">
        <el-table-column label="已放行" width="86" align="center">
          <template #default="{ row }"><span class="link" @click="toOrders(row,'released')">{{ (row.stats || {}).released || 0 }}</span></template>
        </el-table-column>
        <el-table-column label="已查验" width="86" align="center">
          <template #default="{ row }">{{ (row.stats || {}).inspected || 0 }}</template>
        </el-table-column>
        <el-table-column label="已预报" width="86" align="center">
          <template #default="{ row }"><span class="link" @click="toOrders(row,'forecast')">{{ (row.stats || {}).forecast || 0 }}</span></template>
        </el-table-column>
        <el-table-column label="查验中" width="86" align="center">
          <template #default="{ row }"><span class="link" @click="toOrders(row,'inspecting')">{{ (row.stats || {}).inspecting || 0 }}</span></template>
        </el-table-column>
      </template>

      <el-table-column label="状态" width="90" align="center">
        <template #default="{ row }">
          <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'" size="small" disable-transitions>
            {{ Number(row.status) === 1 ? '已启用' : '已停用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="管理状态" width="98" align="center">
        <template #default="{ row }">
          <el-tag :type="Number(row.manage_status) === 1 ? 'success' : 'info'" size="small" disable-transitions>
            {{ Number(row.manage_status) === 1 ? '已启用' : '已停用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="130" align="center" fixed="right">
        <template #default="{ row }">
          <span class="link" @click="openEdit(row)">编辑</span>
          <el-divider direction="vertical" />
          <el-dropdown trigger="click" @command="c => rowCmd(c, row)">
            <span class="link">更多<el-icon><ArrowDown /></el-icon></span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="toggleStatus">{{ Number(row.status) === 1 ? '停用' : '启用' }}</el-dropdown-item>
                <el-dropdown-item command="toggleManage">{{ Number(row.manage_status) === 1 ? '关闭管理状态' : '开启管理状态' }}</el-dropdown-item>
                <el-dropdown-item command="del" divided>删除</el-dropdown-item>
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

  <el-dialog v-model="dlg" :title="dlgTitle + ' · ' + title" width="740px">
    <el-form label-width="104px">
      <div class="basic-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px">
        <el-form-item label="公司名称" required style="grid-column:1 / span 2">
          <el-input v-model="form.company_name" placeholder="请填写完整公司名称（英文抬头）" />
        </el-form-item>
        <el-form-item label="国家"><el-input v-model="form.country" placeholder="如 US" /></el-form-item>
        <el-form-item label="州 / 省"><el-input v-model="form.state_province" placeholder="如 CA" /></el-form-item>
        <el-form-item label="城市"><el-input v-model="form.city" /></el-form-item>
        <el-form-item label="邮编"><el-input v-model="form.zip_code" /></el-form-item>
        <el-form-item label="地址" style="grid-column:1 / span 2"><el-input v-model="form.address" /></el-form-item>
        <el-form-item label="联系人"><el-input v-model="form.contact" /></el-form-item>
        <el-form-item label="电话"><el-input v-model="form.phone" /></el-form-item>
        <el-form-item label="邮箱"><el-input v-model="form.email" /></el-form-item>
        <el-form-item label="BOND 号" v-if="isImporter"><el-input v-model="form.bond_no" placeholder="如 V12345678" /></el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status"><el-radio :value="1">启用</el-radio><el-radio :value="0">停用</el-radio></el-radio-group>
        </el-form-item>
        <el-form-item label="管理状态">
          <el-radio-group v-model="form.manage_status"><el-radio :value="1">启用</el-radio><el-radio :value="0">停用</el-radio></el-radio-group>
        </el-form-item>
        <el-form-item label="备注" style="grid-column:1 / span 2">
          <el-input v-model="form.remark" type="textarea" :rows="2" />
        </el-form-item>
      </div>
    </el-form>
    <template #footer>
      <el-button @click="dlg = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">确定</el-button>
    </template>
  </el-dialog>
</div>`;

  QG.companyPage = function (type) {
    var isImporter = Number(type) === 2;
    var base = QG.listPage({
      base: '/companies',
      query: { type: type },
      buildForm: function (row) {
        var base2 = {
          company_type: Number(type),
          company_name: '', country: '', state_province: '', city: '', zip_code: '',
          address: '', contact: '', phone: '', email: '', bond_no: '', remark: '',
          status: 1, manage_status: 1
        };
        if (!row) return base2;
        Object.keys(base2).forEach(function (k) { if (row[k] !== undefined && row[k] !== null) base2[k] = row[k]; });
        base2.company_type = Number(type);
        base2.status = Number(row.status);
        base2.manage_status = Number(row.manage_status);
        return base2;
      },
      beforeSave: function (f) {
        if (!String(f.company_name || '').trim()) { QG.warn('请填写公司名称'); return false; }
        return f;
      }
    });

    return Object.assign({}, base, {
      template: TPL,
      data: function () {
        var d = base.data.call(this);
        d.isImporter = isImporter;
        d.title = isImporter ? '进口商' : '发货人';
        return d;
      },
      methods: Object.assign({}, base.methods, {
        toOrders: function (row, status) {
          location.href = 'order-customs.html?importer_id=' + row.id + '&customs_status=' + status;
        },
        rowCmd: function (cmd, row) {
          var self = this;
          if (cmd === 'toggleStatus' || cmd === 'toggleManage') {
            var key = cmd === 'toggleStatus' ? 'status' : 'manage_status';
            var val = Number(row[key]) === 1 ? 0 : 1;
            var payload = Object.assign({}, row);
            delete payload.stats;
            delete payload.created_at;
            delete payload.updated_at;
            payload[key] = val;
            QG.put('/companies/' + row.id, payload).then(function () {
              QG.toast(val === 1 ? '已启用' : '已停用');
              self.search();
            }).catch(function (e) { QG.err(e); });
          } else if (cmd === 'del') {
            self.remove(row, '', '确定删除「' + row.company_name + '」吗？删除后不可恢复。');
          }
        }
      })
    });
  };
})(window);
