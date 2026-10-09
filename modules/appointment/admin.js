/**
 * modules/appointment/admin.js
 *
 * ระบบนัดหมายในตัว Hub
 *
 * รายการวาดโดย ApiComponent กับ TemplateManager ใน appointment/index.html
 * ฟอร์มเพิ่ม/แก้อยู่ใน appointment/form.html เปิดเป็น Modal ที่เซิร์ฟเวอร์สั่งเปิด
 * และบันทึกผ่าน FormManager (data-ajax-submit) — แบบเดียวกับฟอร์มระบบต้นทาง
 * ในโมดูล timeline · เหลือใน JS เฉพาะสิ่งที่ประกาศไม่ได้: ปุ่มที่ต้อง POST
 * พร้อม CSRF และสวิตช์ "ทั้งวัน" ที่ต้องปิดช่องเวลา
 */
EventManager.on('router:initialized', () => {
  RouterManager.register('/appointment', {
    template: 'appointment/index.html',
    title: '{LNG_Appointments}',
    requireAuth: true
  });
});

const AppointmentPage = {
  /**
   * @param {string} text
   * @returns {string}
   */
  t(text) {
    return window.Now && typeof Now.translate === 'function' ? Now.translate(text) : text;
  },

  /**
   * @param {Object|null} response
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
      console.log('[Appointment]', message);
    }
  },

  /**
   * @param {string} endpoint
   * @param {Object} fields
   * @returns {Promise<Object|null>} data ชั้นในของคำตอบ · null เมื่อพลาด
   */
  async post(endpoint, fields) {
    const form = new FormData();
    Object.entries(fields).forEach(([key, value]) => form.append(key, value));
    const response = await http.post(endpoint, form);

    // window.http ตั้ง throwOnError:false ไว้ จึงไม่โยน exception เมื่อเซิร์ฟเวอร์
    // ตอบ 4xx และห่อคำตอบไว้อีกชั้น — ที่นี่เคยคืน response.data ซึ่งเป็นซอง
    // {success, data} ของเซิร์ฟเวอร์ ไม่ใช่ข้อมูลจริง ช่องอ่านประโยคเดิมจึงเห็น
    // parsed.date เป็น undefined ทุกครั้งแล้วแจ้งว่าอ่านวันที่ไม่ออกตลอด
    // และข้อผิดพลาดจากเซิร์ฟเวอร์ก็ไม่เคยขึ้นให้เห็นเลย
    if (!response || !response.success) {
      this.toast(this.message(response), true);
      return null;
    }
    EventManager.emit('appointment:reload');

    return response.data.data;
  }
};

const AppointmentForm = {
  /**
   * ขอฟอร์มจากเซิร์ฟเวอร์แล้วให้ ResponseHandler เปิด Modal ให้
   *
   * @param {number|string} id 0 = เพิ่มใหม่
   */
  async open(id) {
    const query = Number(id) > 0 ? '?id=' + encodeURIComponent(id) : '';
    const response = await httpAction.get('api/appointment/items/form' + query);

    // สำเร็จแล้ว Modal ถูกเปิดโดย action ที่แนบมากับคำตอบ — เหลือแค่บอกเมื่อพลาด
    if (!response || !response.success) {
      AppointmentPage.toast(AppointmentPage.message(response), true);
      return;
    }
    const form = document.querySelector('form[data-form="appointment"]');
    if (form) this.syncAllDay(form);
  },

  /**
   * นัดทั้งวันไม่มีเวลา — ปิดช่องเวลาทั้งสองให้เห็นชัด และช่องที่ถูกปิด
   * ไม่ถูกส่งไปกับฟอร์ม เซิร์ฟเวอร์จึงไม่ได้เวลาค้างมาจากตอนก่อนติ๊ก
   *
   * @param {HTMLFormElement} form
   */
  syncAllDay(form) {
    const allDay = form.elements.all_day ? form.elements.all_day.checked : false;
    ['start_time', 'end_time'].forEach((name) => {
      const input = form.elements[name];
      if (!input) return;
      input.disabled = allDay;
      // ช่องเวลาเริ่มต้องกรอกเมื่อไม่ใช่นัดทั้งวัน
      if (name === 'start_time') input.required = !allDay;
    });
  }
};

// ลิงก์ "เปิดนัดหมาย" จากไทม์ไลน์และแชตชี้มาที่ /appointment?id=... —
// เปิดฟอร์มของนัดนั้นให้เลย ไม่ใช่ทิ้งไว้ที่รายการให้ไปหาเอง
EventManager.on('route:changed', (context) => {
  // EventManager ส่ง context มา ค่าที่ router แนบอยู่ใน context.data
  const route = context && context.data;
  if (route && route.path === '/appointment' && route.query && Number(route.query.id) > 0) {
    AppointmentForm.open(route.query.id);
  }
});

document.addEventListener('change', (event) => {
  if (event.target.matches && event.target.matches('[data-appt-all-day]')) {
    AppointmentForm.syncAllDay(event.target.form);
  }
});

document.addEventListener('click', (event) => {
  if (event.target.closest('[data-appt-new]')) {
    AppointmentForm.open(0);
    return;
  }

  const edit = event.target.closest('[data-appt-edit]');
  if (edit) {
    AppointmentForm.open(edit.dataset.apptEdit);
    return;
  }

  const done = event.target.closest('[data-appt-done]');
  if (done) {
    AppointmentPage.post('api/appointment/items/complete', {id: done.dataset.apptDone});
    return;
  }

  const remove = event.target.closest('[data-appt-remove]');
  if (remove) {
    if (!window.confirm(AppointmentPage.t('Delete this appointment?'))) return;
    AppointmentPage.post('api/appointment/items/remove', {id: remove.dataset.apptRemove});
  }
});
