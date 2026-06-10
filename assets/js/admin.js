/**
 * 后台管理页面统一 JavaScript
 *
 * 功能：
 * - 移除帮助标签和屏幕选项
 * - 考勤管理页面的编辑/删除功能
 */

(function() {
    'use strict';

    // 移除帮助标签和屏幕选项元素
    function removeAdminElements() {
        var elements = [
            document.getElementById('screen-meta'),
            document.getElementById('contextual-help-wrap'),
            document.getElementById('screen-meta-links')
        ];
        elements.forEach(function(el) {
            if (el && el.parentNode) {
                el.parentNode.removeChild(el);
            }
        });
    }

    // 页面加载时尽早执行
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', removeAdminElements);
    } else {
        removeAdminElements();
    }

    // 考勤管理页面 - 打开编辑模态框
    window.openEditModal = function(date, hours, status, id) {
        var dateInput = document.getElementById('edit_attendance_date');
        var hoursInput = document.getElementById('edit_work_hours');
        var statusInput = document.getElementById('edit_status');
        var idInput = document.getElementById('edit_attendance_id');
        var modal = document.getElementById('editModal');

        if (dateInput) dateInput.value = date;
        if (hoursInput) hoursInput.value = hours;
        if (statusInput) statusInput.value = status;
        if (idInput) idInput.value = id || '';
        if (modal) modal.style.display = 'block';
    };

    // 考勤管理页面 - 关闭编辑模态框
    window.closeEditModal = function() {
        var modal = document.getElementById('editModal');
        if (modal) modal.style.display = 'none';
    };

    // 考勤管理页面 - 删除记录
    window.deleteRecord = function(id, date) {
        if (confirm('确定要删除 ' + date + ' 的考勤记录吗？')) {
            var deleteIdInput = document.getElementById('delete_attendance_id');
            var deleteForm = document.getElementById('deleteForm');
            if (deleteIdInput && deleteForm) {
                deleteIdInput.value = id;
                deleteForm.submit();
            }
        }
    };
})();
