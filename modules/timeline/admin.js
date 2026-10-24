/**
 * modules/timeline/admin.js
 *
 * หน้าจอ Central Timeline Hub
 *
 * การโหลดและวาดข้อมูลทั้งหมดเป็นงานของ ApiComponent กับ TemplateManager ในไฟล์
 * .html — ที่เหลืออยู่ตรงนี้คือสิ่งที่เขียนแบบประกาศไม่ได้เท่านั้น
 *
 *   1. ปุ่มที่ต้อง POST พร้อม CSRF แล้วสั่งให้จอโหลดใหม่
 *   2. ฟอร์มของ action ที่ระบบต้นทางเป็นคนประกาศว่ามีช่องอะไรบ้าง
 *      (TIMELINE-PROTOCOL.md §6.4) ซึ่งไม่มีทางรู้ล่วงหน้าตอนเขียน template
 *
 * ทุกปุ่มจบด้วยการยิง event ที่ data-refresh-event ของ component ผูกไว้ จึงไม่มี
 * โค้ดวาดจอในไฟล์นี้เลย
 */

/**
 * EventCalendar อยู่คนละบันเดิลกับ now.core (ดู vite.config.js) หน้า / (ปฏิทิน)
 * จึงไม่วาดอะไรเลยถ้าไม่โหลด — และไม่มีข้อผิดพลาดใน console ให้เห็นด้วย
 *
 * โหลดจากโมดูลแทน index.php เพื่อให้ index.php เป็นไฟล์แกนตัวเดียวกับทุกโปรเจ็ค
 * (แบบเดียวกับ modules/demo/admin.js ของ adminframework) · eventcalendar.min.js
 * สแกน [data-event-calendar] เองตอนโหลดจบและทุกครั้งที่ route เปลี่ยน
 * จึงไม่สำคัญว่าจะโหลดเสร็จก่อนหรือหลังหน้าแรกวาด
 */
(() => {
  const src = document.currentScript?.src || '';
  const base = src.replace(/modules\/timeline\/admin\.js.*$/, '');
  if (!base) return;

  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = `${base}Now/dist/eventcalendar.min.css`;
  document.head.appendChild(css);

  const js = document.createElement('script');
  js.src = `${base}Now/dist/eventcalendar.min.js`;
  document.head.appendChild(js);
})();

EventManager.on('router:initialized', () => {
  RouterManager.register('/', {
    template: 'timeline/calendar.html',
    title: '{LNG_Calendar}',
    requireAuth: true
  });

  RouterManager.register('/today', {
    template: 'timeline/today.html',
    title: '{LNG_Today}',
    requireAuth: true
  });

  RouterManager.register('/connections', {
    template: 'timeline/connections.html',
    title: '{LNG_Connections}',
    requireAuth: true
  });

  RouterManager.register('/history', {
    template: 'timeline/history.html',
    title: '{LNG_Notification history}',
    requireAuth: true
  });

  RouterManager.register('/chat-account', {
    template: 'timeline/chat.html',
    title: '{LNG_Chat account}',
    requireAuth: true
  });
});

const TimelineHub = {
  /**
   * @param {string} text
   * @returns {string}
   */
  t(text) {
    return window.Now && typeof Now.translate === 'function' ? Now.translate(text) : text;
  },

  /**
   * ข้อความที่เซิร์ฟเวอร์ส่งมา อ่านได้ทั้งตอนสำเร็จและตอนพลาด
   *
   * @param {Object} response ซองตอบกลับของ HttpClient
   * @returns {string}
   */
  message(response) {
    const body = response ? response.data : null;

    return (body && (body.message || body.errors)) || this.t('Failed');
  },

  /**
   * @param {string} message
   * @param {boolean} isError
   */
  toast(message, isError) {
    // NotificationManager คือตัวแจ้งเตือนของ Now.js — ที่นี่เคยเรียก AlertManager
    // ซึ่งไม่มีอยู่ในบันเดิล ข้อความทุกอันจึงตกไปที่ console แล้วผู้ใช้ไม่เห็นอะไรเลย
    if (window.NotificationManager) {
      NotificationManager[isError ? 'error' : 'success'](message);
    } else {
      console.log('[Timeline]', message);
    }
  },

  /**
   * POST ที่ผ่าน HttpClient (ซึ่งแนบ CSRF ให้เอง) แล้วสั่งจอโหลดใหม่
   *
   * @param {string} endpoint
   * @param {Object} fields
   * @param {string} event ชื่อ event ที่จะยิงเมื่อสำเร็จ
   * @returns {Promise<Object|null>}
   */
  async post(endpoint, fields, event = 'timeline:reload') {
    const form = new FormData();
    Object.entries(fields).forEach(([key, value]) => form.append(key, value));
    const response = await http.post(endpoint, form);

    // window.http ตั้ง throwOnError:false ไว้ จึงไม่โยน exception เมื่อเซิร์ฟเวอร์
    // ตอบ 4xx — ต้องอ่าน success เอง ไม่งั้นเนื้อความของข้อผิดพลาดจะถูกส่งต่อ
    // เหมือนเป็นผลสำเร็จ และจอจะโหลดใหม่ราวกับคำสั่งนั้นผ่าน
    if (!response || !response.success) {
      this.toast(this.message(response), true);
      return null;
    }
    // event เป็น null เมื่อคำสั่งนั้นไม่ควรทำให้จอโหลดใหม่ — เช่นการขอรหัส
    // ผูกบัญชี ซึ่งโหลดใหม่แล้วรหัสที่เพิ่งแสดงจะหายไปทันที
    if (event) {
      EventManager.emit(event);
    }

    // คืนเฉพาะ data ชั้นใน ไม่ใช่ซองทั้งใบ — ผู้เรียกสนใจค่าที่ API ตอบ
    // ไม่ใช่ success/code/request_id ที่ห่อมันอยู่
    return response.data.data;
  },

  /**
   * กดเหตุการณ์บนปฏิทินแล้วต้องได้ข้อมูลครบ ไม่ใช่แค่ชื่อกับวัน
   *
   * ปฏิทินมีที่ให้เขียนแค่บรรทัดเดียวต่อช่อง ทุกอย่างที่ต้นทางส่งมาจึงต้อง
   * มาโผล่ตรงนี้แทน — กติกาเดียวกับแชตและข้อความเตือน
   *
   * @param {Object} event เหตุการณ์ที่ EventCalendar ทำให้เป็นมาตรฐานแล้ว
   */
  showEvent(event) {
    const data = (event && event.data) || event || {};
    const lines = [data.kind_label || '', data.when || '', data.source_name || ''].filter(Boolean);
    const body = [
      (data.title || '').replace(/^✓ /, ''),
      '',
      lines.join(' · '),
      data.description || '',
      data.location ? `📍 ${data.location}` : ''
    ].filter((part, i) => part !== '' || i === 1).join('\n');

    // DialogManager.alert(message, title) — title เป็นอาร์กิวเมนต์ที่สอง
    // ไม่ใช่คีย์ใน options · ส่งผิดที่แล้วหัวข้อจะกลายเป็นคำว่า "Alert"
    if (window.DialogManager && typeof DialogManager.alert === 'function') {
      DialogManager.alert(body, 'Details');
      return;
    }
    window.alert(body);
  },

  /**
   * ยิง action ที่ระบบต้นทางประกาศไว้กับการ์ดใบนี้
   *
   * ฟอร์มสร้างจากนิยามที่ต้นทางส่งมา ไม่ได้ตายตัวใน Hub — Hub ไม่รู้ว่าปุ่มชื่อ
   * log_contact แปลว่าอะไร และต้องไม่มีวันรู้
   *
   * @param {HTMLElement} button
   */
  async runAction(button) {
    const itemId = button.dataset.item;
    const actionId = button.dataset.runAction;

    let fields = [];
    try {
      fields = JSON.parse(button.dataset.fields || '[]');
    } catch (error) {
      fields = [];
    }

    const values = {};
    for (const field of fields) {
      let answer;
      if (field.type === 'select') {
        const list = field.options.map((option, i) => `${i + 1}. ${option.label}`).join('\n');
        const picked = window.prompt(`${field.label}\n${list}`, '1');
        if (picked === null) return;
        const option = field.options[parseInt(picked, 10) - 1];
        if (!option) return;
        answer = option.value;
      } else {
        answer = window.prompt(field.label, field.default || '');
        if (answer === null) return;
      }
      if (field.required && (answer === '' || answer === null)) return;
      values[field.name] = answer;
    }

    const confirmText = button.dataset.confirm;
    if (confirmText && !window.confirm(confirmText)) return;

    const result = await this.post('api/timeline/card/run', {
      id: itemId,
      action: actionId,
      params: JSON.stringify(values)
    });
    if (result) this.toast(result.message || this.t('Saved'));
  }
};

// `const` ที่ระดับบนสุดของสคริปต์ธรรมดาไม่ได้กลายเป็น property ของ window —
// มันอยู่ใน global lexical environment ซึ่งเรียกด้วยชื่อเปล่า ๆ ได้ แต่
// `window.TimelineHub` เป็น undefined · EventCalendar หาฟังก์ชันของ
// data-on-event-click โดยไล่จาก window ลงมาตามจุด ปุ่มจึงเงียบสนิทถ้าไม่ผูกไว้ตรงนี้
window.TimelineHub = TimelineHub;

/**
 * ฟอร์มจัดการระบบต้นทาง
 *
 * ฟอร์มอยู่คนละไฟล์กับตาราง (templates/timeline/source.html) และเปิดเป็น Modal
 * ตามกติกาแยกตาราง/ฟอร์ม — เซิร์ฟเวอร์เป็นคนตอบมาว่าใช้เทมเพลตไหน หัวข้อว่าอะไร
 * และเมื่อบันทึกแล้วต้องปิด Modal กับโหลดตารางใหม่ (ทั้ง /form และ /save ตอบเป็น
 * actions ที่ ResponseHandler ทำตาม) ส่วนการบันทึกเป็นงานของ FormManager ผ่าน
 * data-ajax-submit จึงไม่มีตัวจัดการ submit ในไฟล์นี้
 *
 * เหลือไว้ตรงนี้แค่ปุ่มทดสอบการเชื่อมต่อ ซึ่งไม่ใช่การบันทึกและต้องอ่านค่าที่ยัง
 * ไม่ถูกส่ง จึงเขียนแบบประกาศไม่ได้
 */
/**
 * ฟอร์มกฎการเตือน — โครงเดียวกับ SourceForm เพราะทั้งคู่เป็นฟอร์มใน Modal
 * ที่เซิร์ฟเวอร์เป็นคนสั่งเปิดผ่าน action ที่แนบมากับคำตอบ
 */
const RuleForm = {
  /**
   * @param {number|string} id 0 = เพิ่มใหม่
   */
  async open(id) {
    const query = Number(id) > 0 ? '?id=' + encodeURIComponent(id) : '';
    const response = await httpAction.get('api/timeline/reminders/form' + query);
    if (!response || !response.success) {
      TimelineHub.toast(TimelineHub.message(response), true);
    }
  }
};

const SourceForm = {
  /**
   * ขอฟอร์มจากเซิร์ฟเวอร์แล้วให้ ResponseHandler เปิด Modal ให้
   *
   * @param {number|string} id 0 = เพิ่มใหม่
   */
  async open(id) {
    const query = Number(id) > 0 ? '?id=' + encodeURIComponent(id) : '';
    const response = await httpAction.get('api/timeline/connections/form' + query);

    // สำเร็จแล้ว Modal ถูกเปิดโดย action ที่แนบมากับคำตอบ — เหลือแค่บอกเมื่อพลาด
    if (!response || !response.success) {
      TimelineHub.toast(TimelineHub.message(response), true);
    }
  },

  /**
   * ลองเชื่อมต่อโดยไม่บันทึก — ให้รู้ว่า URL กับ token ถูกไหมก่อนกดบันทึก
   *
   * @param {HTMLFormElement} form ฟอร์มที่อยู่ใน Modal
   */
  async test(form) {
    const note = form.querySelector('#source-test');
    if (note) note.textContent = TimelineHub.t('Testing') + '...';

    const result = await TimelineHub.post('api/timeline/connections/test', {
      // ส่ง id ไปด้วย เพื่อให้เซิร์ฟเวอร์เติมค่าที่เก็บไว้ให้ในช่องที่เว้นว่าง
      // — ตอนกดแก้ ฟอร์มไม่เคยมีรหัสเดิมอยู่ในมือ
      id: form.elements.id.value,
      base_url: form.elements.base_url.value,
      token: form.elements.token.value
    }, null);

    if (!note) return;
    note.textContent = '';
    const box = document.createElement('div');
    box.className = result && result.ok ? 'tl-test is-ok' : 'tl-test is-bad';
    box.textContent = result ? result.message : TimelineHub.t('Failed');
    note.appendChild(box);

    // ปลายทางบอกชื่อย่อของตัวเองมา เติมให้เลยถ้าช่องยังว่าง — ชื่อย่อที่ไม่ตรงกัน
    // คือสาเหตุที่ sync ล้มเหลวแบบที่หาไม่เจอ
    if (result && result.ok && !form.elements.slug.value) {
      form.elements.slug.value = result.slug;
      if (!form.elements.name.value) form.elements.name.value = result.name;
    }
  }
};

/* ผูกครั้งเดียวที่ document — การ์ดถูกวาดใหม่ทุกครั้งที่โหลดข้อมูล
   การผูกที่ตัวปุ่มจะหลุดทุกรอบ */
document.addEventListener('click', (event) => {
  const run = event.target.closest('[data-run-action]');
  if (run) {
    TimelineHub.runAction(run);
    return;
  }

  const state = event.target.closest('[data-state]');
  if (state) {
    TimelineHub.post('api/timeline/card/state', {id: state.dataset.item, state: state.dataset.state, days: 0});
    return;
  }

  const snooze = event.target.closest('[data-snooze]');
  if (snooze) {
    TimelineHub.post('api/timeline/card/state', {
      id: snooze.dataset.item,
      state: 'snoozed',
      days: snooze.dataset.snooze
    });
    return;
  }

  const sync = event.target.closest('[data-timeline-sync]');
  if (sync) {
    TimelineHub.toast(TimelineHub.t('Syncing') + '...');
    TimelineHub.post('api/timeline/connections/sync', {id: sync.dataset.timelineSync || 0});
    return;
  }

  if (event.target.closest('[data-rule-new]')) {
    RuleForm.open(0);
    return;
  }

  const ruleEdit = event.target.closest('[data-rule-edit]');
  if (ruleEdit) {
    RuleForm.open(ruleEdit.dataset.ruleEdit);
    return;
  }

  const ruleRemove = event.target.closest('[data-rule-remove]');
  if (ruleRemove) {
    const name = ruleRemove.dataset.ruleName || '';
    if (!window.confirm(`${TimelineHub.t('Delete this reminder rule?')}\n\n${name}`)) return;
    TimelineHub.post('api/timeline/reminders/remove', {id: ruleRemove.dataset.ruleRemove}, 'reminders:reload');
    return;
  }

  const rule = event.target.closest('[data-reminder-toggle]');
  if (rule) {
    TimelineHub.post(
      'api/timeline/reminders/toggle',
      {id: rule.dataset.reminderToggle, enabled: rule.dataset.reminderNext},
      'reminders:reload'
    );
    return;
  }

  const board = event.target.closest('[data-board-toggle]');
  if (board) {
    TimelineHub.post(
      'api/timeline/reminders/toggle',
      {id: board.dataset.boardToggle, enabled: board.dataset.boardNext, field: 'visible'},
      'reminders:reload'
    ).then((data) => {
      // หน้า "วันนี้" อ่านจากชุดกฎเดียวกัน ซ่อน/แสดงแล้วต้องเห็นผลทันที
      // ไม่ใช่รอผู้ใช้กดรีเฟรชเอง แล้วสงสัยว่าสวิตช์ทำงานหรือเปล่า
      if (data) EventManager.emit('timeline:reload');
    });
    return;
  }

  const unlink = event.target.closest('[data-unlink]');
  if (unlink) {
    if (!window.confirm(TimelineHub.t('Unlink this chat room?'))) return;
    TimelineHub.post('api/timeline/chatlink/revoke', {channel: unlink.dataset.unlink}, 'chatlink:reload');
    return;
  }

  if (event.target.closest('[data-source-new]')) {
    SourceForm.open(0);
    return;
  }

  const edit = event.target.closest('[data-source-edit]');
  if (edit) {
    SourceForm.open(edit.dataset.sourceEdit);
    return;
  }

  const test = event.target.closest('[data-source-test]');
  if (test) {
    // ปุ่มอยู่ใน Modal ที่ถูกสร้างทีหลัง จึงหาฟอร์มจากตัวปุ่มเอง
    // ไม่ใช่จาก id ที่ค้างอยู่ในหน้า
    const form = test.closest('form');
    if (form) SourceForm.test(form);
    return;
  }

  const removeSource = event.target.closest('[data-source-remove]');
  if (removeSource) {
    const name = removeSource.dataset.sourceName || '';
    // บอกให้ชัดว่าลบแล้วอะไรหายไปด้วย — รายการที่ดึงมาจากระบบนั้นหายทั้งหมด
    if (!window.confirm(`${TimelineHub.t('Delete this application and everything synced from it?')}\n\n${name}`)) return;
    TimelineHub.post('api/timeline/connections/remove', {id: removeSource.dataset.sourceRemove});
    return;
  }

  if (event.target.closest('[data-history-clear]')) {
    // ลบตามตัวกรองที่เปิดอยู่ ไม่ใช่ลบทั้งตารางเสมอ — คนกรองไว้ว่า
    // "เฉพาะ alert.security" แล้วกดล้าง ย่อมหมายถึงเฉพาะที่เห็นอยู่
    // TableManager เก็บค่าตัวกรองปัจจุบันไว้ที่ config.params ของตารางนั้น
    const state = window.TableManager?.state?.tables?.get('timelineHistory');
    const params = state?.config?.params || {};
    const fields = {action: 'clear'};
    ['search', 'status', 'kind', 'source_id'].forEach((key) => {
      if (params[key]) fields[key] = params[key];
    });

    const scoped = Object.keys(fields).length > 1;
    const question = scoped
      ? TimelineHub.t('Delete the history rows matching the current filter?')
      : TimelineHub.t('Delete the entire notification history?');
    if (!window.confirm(`${question}\n\n${TimelineHub.t('Sent rows go. Queued rows stay unless the thing they are about is already gone')}`)) return;

    // httpAction ไม่ใช่ TimelineHub.post — คำตอบของ Gcms\Table มาเป็น actions
    // (notification + redirect target:table) ซึ่งมีแต่ httpAction ที่เดินให้
    // ส่วน reloadTable ใน context คือทางที่ ResponseHandler ใช้หาตารางที่จะโหลดใหม่
    httpAction.post('api/timeline/history/action', fields, {
      reloadTable: () => TableManager.loadTableData('timelineHistory', {force: true})
    }).then((response) => {
      if (!response || !response.success) {
        TimelineHub.toast(TimelineHub.message(response), true);
      }
    });
    return;
  }

  if (event.target.closest('[data-issue-code]')) {
    TimelineHub.post('api/timeline/chatlink/issue', {}, null).then((data) => {
      const box = document.getElementById('timeline-code');
      if (!data || !box) return;
      // รหัสต้องอ่านและพิมพ์ตามได้ง่าย เพราะผู้ใช้พิมพ์มันในอีกเครื่องหนึ่ง
      box.textContent = '';
      const wrap = document.createElement('div');
      wrap.className = 'timeline-code';
      const code = document.createElement('b');
      code.textContent = data.code;
      const note = document.createElement('span');
      note.textContent = `${TimelineHub.t('Valid for')} ${data.ttl_minutes} ${TimelineHub.t('minutes')}`;
      const cmd = document.createElement('code');
      cmd.textContent = `/link ${data.code}`;
      wrap.append(code, note, cmd);
      box.appendChild(wrap);
    });
  }
});
