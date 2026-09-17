(function () {
  'use strict';

  document.querySelectorAll('[data-content-schedule]').forEach(function (field) {
    var form = field.closest('form');
    var status = form ? form.querySelector('[name="status"]') : null;
    var input = field.querySelector('[name="schedule_at"]');
    if (!status || !input) return;

    function sync() {
      var scheduled = status.value === 'scheduled';
      field.hidden = !scheduled;
      input.disabled = !scheduled;
      input.required = scheduled;
    }

    status.addEventListener('change', sync);
    sync();
  });
})();
