/**
 * 前端短代码 JavaScript - 优化版本 v2.0
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('isoftstoneForm');
        const yearSelect = document.getElementById('select_year');
        const monthSelect = document.getElementById('select_month');
        const calendarBody = document.getElementById('calendarBody');
        const loadingOverlay = document.getElementById('isoftstoneLoadingOverlay');
        const submitBtn = document.getElementById('isoftstoneSubmitBtn');

        // 如果关键元素不存在，提前返回
        if (!form || !yearSelect || !monthSelect || !calendarBody) {
            return;
        }

        // 从 PHP 传递的数据
        const currentYear = parseInt(yearSelect.value) || new Date().getFullYear();
        const currentMonth = parseInt(monthSelect.value) || new Date().getMonth() + 1;
        const initialYear = parseInt(yearSelect.value) || currentYear;
        const initialMonth = parseInt(monthSelect.value) || currentMonth;

        let attendanceData = typeof isoftstoneData !== 'undefined' ? isoftstoneData : null;

        // 生成日历
        function buildCalendar(year, month, data = null) {
            const firstDay = new Date(year, month - 1, 1);
            let startDay = firstDay.getDay();

            // 转换为周一为第一天的格式（周日=0, 周一=1 ... 周六=6）
            // 变成：周一=0, 周二=1 ... 周日=6
            startDay = (startDay === 0) ? 6 : startDay - 1;

            const daysInMonth = new Date(year, month, 0).getDate();

            let html = '';

            // 填充空白格（月前的空白天数）
            for (let i = 0; i < startDay; i++) {
                html += '<div class="isoftstone-calendar-day isoftstone-calendar-day-empty"></div>';
            }

            // 填充日期
            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const record = data?.[dateStr] || attendanceData?.[dateStr];
                const hours = record?.workHour || 0;
                const datetype = record?.datetype || null;
                const status = record?.status || '';

                // 确定考勤信息的样式类
                let infoClass = 'isoftstone-attendance-info';

                if (record) {
                    // 如果有考勤记录，根据类型和工时显示颜色
                    if (status === '请假' || status === '缺勤') {
                        infoClass += ' info-leave';
                    } else if (datetype === '节假日' || hours === 0) {
                        infoClass += ' info-rest';
                    } else {
                        // 工作日根据工时显示颜色
                        if (hours <= 8) {
                            infoClass += ' info-normal';
                        } else if (hours > 8 && hours <= 9) {
                            infoClass += ' info-overtime-mild';
                        } else {
                            infoClass += ' info-overtime-severe';
                        }
                    }
                }

                html += `
                <div class="isoftstone-calendar-day" title="${dateStr}">
                    <span class="isoftstone-day-number">${d}</span>
                    ${record ? `
                    <div class="${infoClass}">
                        <div class="hours">${hours}h</div>
                        <div class="status">${record.status || '正常'}</div>
                    </div>
                    ` : '<div class="isoftstone-calendar-day-placeholder"></div>'}
                </div>`;
            }

            calendarBody.innerHTML = html;

            // 添加淡入动画
            calendarBody.style.opacity = '0';
            requestAnimationFrame(() => {
                calendarBody.style.transition = 'opacity 0.3s ease';
                calendarBody.style.opacity = '1';
            });
        }

        // 监听日期选择变化
        function updateCalendar() {
            const year = parseInt(yearSelect.value);
            const month = parseInt(monthSelect.value);
            buildCalendar(year, month);
        }

        yearSelect.addEventListener('change', updateCalendar);
        monthSelect.addEventListener('change', updateCalendar);

        // 表单提交处理
        form.addEventListener('submit', (e) => {
            // 显示加载遮罩层
            if (loadingOverlay) {
                loadingOverlay.style.display = 'flex';
            }

            // 更新按钮状态
            if (submitBtn) {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
            }
        });

        // 表单提交前禁用选择框
        form.addEventListener('formdata', (e) => {
            yearSelect.disabled = true;
            monthSelect.disabled = true;
        });

        // 初始化日历
        buildCalendar(initialYear, initialMonth);

        // 自动禁用未来月份（当选择当前年份时）
        yearSelect.addEventListener('change', () => {
            const selectedYear = parseInt(yearSelect.value);
            const currentYearNow = new Date().getFullYear();
            const currentMonthNow = new Date().getMonth() + 1;

            // 启用所有月份
            for (let option of monthSelect.options) {
                option.disabled = false;
            }

            // 如果是当前年份，禁用未来月份
            if (selectedYear === currentYearNow) {
                for (let option of monthSelect.options) {
                    const monthValue = parseInt(option.value);
                    if (monthValue > currentMonthNow) {
                        option.disabled = true;
                    }
                }
            }
        });

        // 页面加载时触发一次月份检查
        yearSelect.dispatchEvent(new Event('change'));
    });
})();
