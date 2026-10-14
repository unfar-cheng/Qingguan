(function (w) {
  'use strict';

  var API = '/api';

  function qs(params) {
    if (!params) return '';
    var parts = [];
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (v === undefined || v === null || v === '') return;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    });
    return parts.length ? '?' + parts.join('&') : '';
  }

  /* ---------- 跨 iframe 缓存失效总线 ----------
     每个 iframe 是独立 window，QG.options() 把结果缓存在各自的 window.QG_OPTS。
     外壳（index.html）用 v-show 保活标签，切回去不会重新加载页面 —— 所以
     「系统设置里改了报价方案 / 抬头」必须主动广播，否则创建订单页会一直拿着旧数据。
     子页面把消息 postMessage 给父窗口（外壳），由外壳中继给其它 iframe（同源）。 */
  var OPTS_MSG = 'qg:opts-invalid';       // 公共下拉数据源变了
  var OPTS_REFRESH = 'qg:opts-refresh';   // 请重新拉一次（外壳切标签时下发）
  /* 写这些接口会动到公共下拉数据（报价方案 / 抬头 / 字典 / 收发信息）。
     ⚠️ 字典必须写成 dicts?（复数可选）：后端「字典维护」的写接口是
     POST|PUT|DELETE /dicts，而 /dict 只是读接口 /dict/options 的前缀。
     只写 dict 会让 /dicts 匹配不上 → 在字典维护里新增/停用字典后不广播，
     别的 iframe（尤其创建订单页）一直拿着旧字典缓存，下拉里看不到新项。
     —— 本项目真实踩过，务必保留 s?。 */
  var OPTS_SOURCES = /^\/(plans|companies|dicts?|configs)(\/|$)/;
  var optsSubs = [];

  function postUp(msg) {
    try { if (w.parent && w.parent !== w) w.parent.postMessage(msg, '*'); } catch (e) {}
  }

  /* 强制重取公共下拉，并把结果分发给所有订阅者（只发一次请求） */
  function refreshOpts() {
    return QG.options(true).then(function (o) {
      optsSubs.slice().forEach(function (cb) { try { cb(o); } catch (e) {} });
      return o;
    });
  }

  function onOptsInvalid() {
    w.QG_OPTS = null;
    if (optsSubs.length) refreshOpts();
  }

  w.addEventListener('message', function (e) {
    var d = e && e.data;
    if (!d || typeof d !== 'object') return;
    if (d.type === OPTS_MSG) onOptsInvalid();
    else if (d.type === OPTS_REFRESH && optsSubs.length) refreshOpts();
  });

  /* ---------- 版本号（静态站点根的 version.json） ----------
     为什么需要它：外壳是 iframe + v-show 保活，**已经打开的标签切回去不会重新加载**，
     所以「覆盖文件后仍然显示旧页面」这件事，光靠服务器缓存头治不了 ——
     页面压根不会再发请求。这里给每一页挂一个版号水印，外壳再按版号决定要不要刷新标签：
       · 水印版号 ≠ 服务器版号 → 你看到的是旧副本，一眼就能判断，不用再猜；
       · 点水印            → 带随机参数重新加载本页（绕过一切缓存）；
     版本号由 _tools/pack-zips.py 在打包时按前端文件内容哈希生成，随包一起解压到站点根。 */
  var BUILD_URL = '/version.json';

  function fetchBuild() {
    /* no-store + 时间戳双保险：这个请求本身绝不能被缓存，否则检测就失效了 */
    return fetch(BUILD_URL + '?_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });   /* 没有 version.json 也只是没水印，不影响功能 */
  }

  /* 可重复调用：元素已存在就只改文本。
     为什么要能改：外壳 + 所有标签里，只有外壳**不会**跟着标签一起重载，
     所以刷新标签到新版号后必须把外壳水印也同步过去 ——
     否则右下角一直挂着旧号，用户看到旧号会以为「还是没更新」，水印反而变成干扰。 */
  function paintStamp(v) {
    if (!v || !v.build) return;
    var el = w.document.getElementById('qg-version-stamp');
    if (!el) {
      el = w.document.createElement('div');
      el.id = 'qg-version-stamp';
      /* 样式内联，不依赖 app.css —— 否则「改了样式但 css 被缓存」会让水印本身出问题 */
      el.setAttribute('style', 'position:fixed;right:6px;bottom:4px;z-index:1500;'
        + 'font:11px/16px Menlo,Consolas,monospace;padding:0 6px;border-radius:8px;'
        + 'background:rgba(255,255,255,.75);color:#c2c8d0;cursor:pointer;user-select:none');
      el.addEventListener('click', function () { w.QG.hardReload(); });
      (w.document.body || w.document.documentElement).appendChild(el);
    }
    el.textContent = v.build;
    el.title = '页面版本 ' + v.build + (v.time ? '（' + v.time + ' 构建）' : '')
      + '\n点一下 = 强制重新加载本页';
  }

  function request(method, path, data, isForm) {
    var url = API + path;
    var opt = { method: method, credentials: 'same-origin', headers: {} };
    if (method === 'GET' || method === 'DELETE') {
      url += qs(data);
    } else if (isForm) {
      opt.body = data;
    } else {
      opt.headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(data || {});
    }
    return fetch(url, opt).then(function (r) {
      return r.text().then(function (t) {
        var j;
        try { j = JSON.parse(t); }
        catch (e) { throw new Error('接口返回异常：' + t.slice(0, 120)); }
        if (j.code === 401) {
          if (w.top !== w) { w.top.location.href = '/login.html'; }
          else { w.location.href = '/login.html'; }
          throw new Error('未登录');
        }
        if (j.code !== 0) throw new Error(j.msg || '请求失败');
        /* 写操作动了公共下拉数据 → 广播失效，其它 iframe 的旧缓存立刻作废 */
        if (method !== 'GET' && OPTS_SOURCES.test(path)) QG.invalidateOpts();
        return j.data;
      });
    });
  }

  var QG = {
    request: request,
    get: function (p, q) { return request('GET', p, q); },
    post: function (p, d) { return request('POST', p, d); },
    put: function (p, d) { return request('PUT', p, d); },
    del: function (p, q) { return request('DELETE', p, q); },
    upload: function (file, extra) {
      var fd = new FormData();
      fd.append('file', file);
      if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
      return request('POST', '/upload', fd, true);
    },

    money: function (v) {
      if (v === null || v === undefined || v === '') return '';
      var n = Number(v);
      if (isNaN(n)) return v;
      return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    },
    date: function (v) {
      if (!v) return '';
      return String(v).slice(0, 10);
    },
    dateTime: function (v) {
      if (!v) return '';
      return String(v).slice(0, 16);
    },
    fileSize: function (n) {
      n = Number(n) || 0;
      if (n < 1024) return n + ' B';
      if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
      return (n / 1048576).toFixed(2) + ' MB';
    },

    toast: function (msg, type) {
      return ElementPlus.ElMessage({ message: msg, type: type || 'success', duration: 2200 });
    },
    warn: function (msg) { return QG.toast(msg, 'warning'); },
    err: function (e) {
      var msg = (e && e.message) ? e.message : String(e);
      if (msg !== '未登录') ElementPlus.ElMessage.error(msg);
      return msg;
    },
    confirm: function (msg, title) {
      return ElementPlus.ElMessageBox.confirm(msg, title || '请确认', {
        type: 'warning', confirmButtonText: '确定', cancelButtonText: '取消'
      });
    },
    alert: function (msg, title) {
      return ElementPlus.ElMessageBox.alert(msg, title || '提示', { confirmButtonText: '知道了' });
    },

    can: function (code) {
      var p = (w.QG_USER && w.QG_USER.permissions) || [];
      return p.indexOf('*') >= 0 || p.indexOf(code) >= 0;
    },

    /* 控制台一键自检：在任意页面的 F12 Console 里敲 QG.selfCheck()
       会打印「当前账号 / 角色 / 权限 / 实际下发的菜单 / 下拉数据条数」，用来定位
       "某个角色看不到某条数据" 到底是菜单没授权还是接口没返回。 */
    selfCheck: function () {
      return Promise.all([QG.get('/auth/me'), QG.get('/menu'), QG.get('/dict/options')])
        .then(function (r) {
          var u = r[0] || {}, ms = r[1] || [], o = r[2] || {};
          var info = {
            '账号': u.username,
            '角色': u.role_code + ' / ' + (u.role_name || ''),
            '权限code': (u.permissions || []).join(',') || '(空)',
            '菜单': ms.map(function (m) {
              return (m.children && m.children.length)
                ? m.name + ' → ' + m.children.map(function (c) { return c.name; }).join(' / ')
                : m.name;
            }).join(' ｜ '),
            '发货人条数': (o.shippers || []).length,
            '进口商条数': (o.importers || []).length,
            '方案条数': (o.plans || []).length,
            '发货人名单': (o.shippers || []).map(function (x) { return x.company_name; }).join(' | ')
          };
          if (console.table) console.table(info); else console.log(info);
          console.log('可视化自检页：' + location.origin + '/tools/selfcheck.html');
          return info;
        })
        .catch(function (e) { console.error('自检失败：', e); });
    },

    /* ---------- 公共下拉数据（字典 + 进口商 + 发货人 + 方案），进程内缓存 ---------- */
    options: function (force) {
      if (!force && w.QG_OPTS) return Promise.resolve(w.QG_OPTS);
      return QG.get('/dict/options').then(function (o) {
        w.QG_OPTS = o || {};
        w.QG_OPTS.dict      = w.QG_OPTS.dict || {};
        w.QG_OPTS.shippers  = w.QG_OPTS.shippers || [];
        w.QG_OPTS.importers = w.QG_OPTS.importers || [];
        w.QG_OPTS.plans     = w.QG_OPTS.plans || [];
        w.QG_OPTS_ERROR = null;
        return w.QG_OPTS;
      }).catch(function (e) {
        /* ⚠️ 这里以前没有 catch —— 接口一挂，所有下拉会「静默变空」，
           使用者只看到"下拉里没有数据"，完全不知道是接口错了还是真没数据，
           排查成本极高（本项目真实踩过）。现在必须报出来。 */
        var msg = (e && e.message) ? e.message : String(e);
        w.QG_OPTS = null;                 // 失败结果不缓存，下次还能自动重试
        w.QG_OPTS_ERROR = msg;
        if (msg !== '未登录') {
          ElementPlus.ElMessage.error('下拉数据（字典 / 抬头）加载失败：' + msg);
        }
        return { dict: {}, importers: [], shippers: [], plans: [], __error: msg };
      });
    },
    /* 数据源变更后调用：清本窗口缓存 + 通知其它 iframe 一起清（写 /plans /companies /dict /configs 时自动触发） */
    invalidateOpts: function () {
      w.QG_OPTS = null;
      postUp({ type: OPTS_MSG });
      if (optsSubs.length) refreshOpts();
    },
    /* 订阅公共下拉数据；缓存失效或外壳切标签时，会带着最新数据回调一次 */
    onOptsChange: function (cb) {
      if (typeof cb === 'function' && optsSubs.indexOf(cb) < 0) optsSubs.push(cb);
      return cb;
    },
    offOptsChange: function (cb) {
      var i = optsSubs.indexOf(cb);
      if (i >= 0) optsSubs.splice(i, 1);
    },
    /* 这个接口路径的「写操作」会不会影响公共下拉数据（= 要不要广播缓存失效）。
       抽成方法是为了能被自动化测试断言，也方便以后加接口时自检。 */
    isOptsSource: function (path) { return OPTS_SOURCES.test(String(path || '')); },

    /* ---------- 版号 ---------- */
    BUILD: null,                    /* 本页加载时服务器上的版本号（水印与外壳对比都用它） */
    fetchBuild: fetchBuild,         /* 回源取版号（永远不缓存） */
    paintStamp: paintStamp,         /* 挂/更新右下角水印（外壳刷新标签后要同步新版号） */

    /* 强制重新加载本页（带随机参数，绕过一切缓存）。水印点击走的是同一套逻辑。 */
    hardReload: function () {
      var u = w.location.pathname + w.location.search;
      w.location.href = u + (u.indexOf('?') >= 0 ? '&' : '?') + '_r=' + Date.now();
    },

    /* 字典 -> [{label,value}] */
    dictList: function (type) {
      var d = (w.QG_OPTS && w.QG_OPTS.dict && w.QG_OPTS.dict[type]) || [];
      return d.map(function (x) { return { label: x.value, value: x.key }; });
    },

    /* 清关状态标签颜色 */
    statusTag: function (s) {
      return ({ forecast: 'info', declaring: 'warning', inspecting: 'danger', released: 'success' })[s] || 'info';
    },

    /* ---------- 挂载 Vue 应用 ---------- */
    mount: function (setup) {
      var opts = setup();
      var app = Vue.createApp(opts);
      app.use(ElementPlus, { size: 'default', zIndex: 3000 });
      if (w.ElementPlusIconsVue) {
        Object.keys(w.ElementPlusIconsVue).forEach(function (k) {
          app.component(k, w.ElementPlusIconsVue[k]);
        });
      }
      app.config.globalProperties.$qg = QG;
      return app.mount(opts.el || '#app');
    },

    /* 载入当前用户（子页面里用，外壳已载过则复用） */
    me: function () {
      if (w.QG_USER) return Promise.resolve(w.QG_USER);
      return QG.get('/auth/me').then(function (u) { w.QG_USER = u; return u; });
    },

    /**
     * 标准列表页混入。cfg:
     *   base        接口基址，如 '/orders'
     *   query       默认查询条件（不含 page / pageSize）
     *   noOpts      true 表示不加载公共下拉
     *   buildForm(row)  打开弹窗时构造表单对象（row 为 null 表示新增）
     *   beforeSave(form)  提交前加工
     *   afterLoad(data)   列表返回后回调
     *   onSaved()         保存成功后回调（默认刷新列表）
     *
     * 上面四个回调都以「组件实例」为 this 调用，所以回调里可以直接
     * 用 this.search() / this.rows / this.form，不必自己在外层存 self。
     */
    listPage: function (cfg) {
      cfg = cfg || {};
      return {
        data: function () {
          return {
            q: Object.assign({ page: 1, pageSize: 50 }, cfg.query || {}),
            rows: [], total: 0, loading: false,
            dlg: false, dlgTitle: '', saving: false,
            form: {}, fid: null,
            opts: { dict: {}, importers: [], shippers: [], plans: [], __error: null }
          };
        },
        created: function () {
          var self = this;
          var jobs = [self.search()];
          if (!cfg.noOpts) {
            jobs.push(QG.options().then(function (o) { self.opts = o; }));
            /* 别处改了方案 / 抬头 / 字典时，本页下拉跟着刷新 */
            QG.onOptsChange(function (o) { self.opts = o; });
          }
          QG.me().catch(function () {});
        },
        methods: {
          loadOpts: function () {
            var self = this;
            return QG.options(true).then(function (o) { self.opts = o; });
          },
          search: function (resetPage) {
            var self = this;
            if (resetPage) self.q.page = 1;
            self.loading = true;
            return QG.get(cfg.base, self.q).then(function (d) {
              if (d && d.list !== undefined) {
                self.rows = d.list || [];
                self.total = d.total || 0;
              } else {
                self.rows = d || [];
                self.total = self.rows.length;
              }
              if (cfg.afterLoad) cfg.afterLoad.call(self, d);
            }).catch(function (e) { QG.err(e); })
              .finally(function () { self.loading = false; });
          },
          reset: function () {
            this.q = Object.assign({ page: 1, pageSize: this.q.pageSize }, cfg.query || {});
            this.search();
          },
          pageChange: function (p) { this.q.page = p; this.search(); },
          sizeChange: function (s) { this.q.pageSize = s; this.q.page = 1; this.search(); },

          openCreate: function (extra) {
            this.fid = null;
            this.dlgTitle = '新增';
            this.form = Object.assign({}, (cfg.buildForm ? cfg.buildForm.call(this, null) : {}), extra || {});
            this.dlg = true;
          },
          openEdit: function (row) {
            this.fid = row.id;
            this.dlgTitle = '编辑';
            this.form = cfg.buildForm ? cfg.buildForm.call(this, row) : Object.assign({}, row);
            this.dlg = true;
          },
          save: function () {
            var self = this;
            var data = cfg.beforeSave ? cfg.beforeSave.call(self, Object.assign({}, self.form)) : self.form;
            if (data === false) return;
            self.saving = true;
            var p = self.fid ? QG.put(cfg.base + '/' + self.fid, data) : QG.post(cfg.base, data);
            p.then(function () {
              QG.toast(self.fid ? '已保存' : '已新增');
              self.dlg = false;
              if (cfg.onSaved) cfg.onSaved.call(self); else self.search();
            }).catch(function (e) { QG.err(e); })
              .finally(function () { self.saving = false; });
          },
          remove: function (row, sub, tip) {
            var self = this;
            return QG.confirm(tip || '确定删除这条记录吗？删除后不可恢复。', '删除确认').then(function () {
              return QG.del(cfg.base + '/' + row.id + (sub || '')).then(function () {
                QG.toast('已删除');
                if (cfg.onSaved) cfg.onSaved.call(self); else self.search();
              });
            }).catch(function (e) { if (e && e.message && e.message !== 'cancel') QG.err(e); });
          }
        }
      };
    }
  };

  w.QG = QG;

  /* 挂版号水印：任何页面（含外壳）加载后都会去问一次服务器当前版号。
     拿不到（还没放过 version.json）就什么都不做，不能因此影响页面功能。 */
  try {
    if (w.document) {
      fetchBuild().then(function (v) {
        if (!v || !v.build) return;
        QG.BUILD = v;
        if (w.document.body) paintStamp(v);
        else w.document.addEventListener('DOMContentLoaded', function () { paintStamp(v); });
      });
    }
  } catch (e) {}
})(window);
