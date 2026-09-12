/* ===== Frontend JavaScript: Payment Method Selector + Installment Calculator ===== */

(function ($) {
    'use strict';

    var persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function toPersian(num) {
        return String(num).replace(/[0-9]/g, function (d) {
            return persianDigits[d];
        });
    }

    function formatToman(amount) {
        amount = Math.round(amount);
        var formatted = amount.toLocaleString('en-US');
        return toPersian(formatted) + ' تومان';
    }

    /* --- Calculation utility (matches PHP calculate_for_plan logic) --- */
    function calculatePlan(price, dpType, dpValue, months, interestRate) {
        if (price <= 0 || months < 1) {
            return null;
        }

        var downPayment;
        if (dpType === 'percentage') {
            downPayment = price * (dpValue / 100);
        } else {
            downPayment = dpValue;
        }
        if (downPayment > price) downPayment = price;
        if (downPayment < 0) downPayment = 0;

        var remaining = price - downPayment;
        var interestAmount = remaining * (interestRate / 100);
        var totalInstallmentAmount = remaining + interestAmount;
        var monthlyInstallment = totalInstallmentAmount / months;
        var totalPayable = downPayment + totalInstallmentAmount;

        return {
            downPayment: downPayment,
            remaining: remaining,
            interestAmount: interestAmount,
            monthlyInstallment: monthlyInstallment,
            totalInstallmentAmount: totalInstallmentAmount,
            totalPayable: totalPayable,
            months: months
        };
    }

    /* --- Main controller --- */
    function updateInstallmentDetails() {
        var data = window.wcipData;
        if (!data || !data.enabled) {
            return;
        }

        var $wrapper = $('.wcip-payment-selector-wrapper');
        var method = $wrapper.find('input[name="wcip_payment_method"]:checked').val();
        var planIdx = parseInt($wrapper.find('input[name="wcip_installment_plan"]:checked').val(), 10);
        var $details = $wrapper.find('.wcip-installment-details');
        var $error = $wrapper.find('#wcip-plan-error');

        if (method === 'cash') {
            $details.slideUp(200);
            $wrapper.find('#wcip-selected-method').val('cash');
            $wrapper.find('#wcip-selected-plan').val('');
            $error.hide();
            return;
        }

        // Installment mode
        $details.slideDown(200);
        $wrapper.find('#wcip-selected-method').val('installment');

        // Validate plan selection
        if (isNaN(planIdx) || !data.plans[planIdx]) {
            $error.show();
            $wrapper.find('#wcip-selected-plan').val('');
            return;
        }

        $error.hide();
        $wrapper.find('#wcip-selected-plan').val(planIdx);

        var plan = data.plans[planIdx];
        var calc = calculatePlan(
            data.productPrice,
            data.downPaymentType,
            data.downPaymentValue,
            plan.months,
            plan.interestRate
        );

        if (!calc) {
            return;
        }

        $wrapper.find('#wcip-down-payment').text(formatToman(calc.downPayment));
        $wrapper.find('#wcip-monthly-installment').text(formatToman(calc.monthlyInstallment));
        $wrapper.find('#wcip-installment-count').text(toPersian(plan.months) + ' ماه');
        $wrapper.find('#wcip-total-payable').text(formatToman(calc.totalPayable));
    }

    /* --- Add to cart: intercept and append hidden fields via AJAX data --- */
    function hookAddToCart() {
        $(document).on('submit', 'form.cart', function () {
            var $form = $(this);
            var $wrapper = $form.find('.wcip-payment-selector-wrapper');

            if ($wrapper.length === 0) {
                return;
            }

            var method = $wrapper.find('input[name="wcip_payment_method"]:checked').val();
            var planIdx = $wrapper.find('input[name="wcip_installment_plan"]:checked').val();

            // Remove any previously injected hidden fields.
            $form.find('.wcip-cart-hidden-fields').remove();

            var $hidden = $('<div class="wcip-cart-hidden-fields" style="display:none;"></div>');
            $hidden.append('<input type="hidden" name="wcip_selected_method" value="' + (method || 'cash') + '" />');
            $hidden.append('<input type="hidden" name="wcip_selected_plan" value="' + (planIdx !== undefined ? planIdx : '') + '" />');
            $form.append($hidden);
        });
    }

    $(document).ready(function () {
        updateInstallmentDetails();

        // React to payment method change.
        $(document).on('change', 'input[name="wcip_payment_method"]', function () {
            updateInstallmentDetails();
        });

        // React to plan selection change.
        $(document).on('change', 'input[name="wcip_installment_plan"]', function () {
            updateInstallmentDetails();
        });

        hookAddToCart();
        initAccountInstallments();
        initNotificationCenter();
    });

    /* ===== My Account — Installments page ===== */

    var pollTimers = {};
    var pollInterval = 3000;
    var maxPollAttempts = 40;

    function initAccountInstallments() {
        if (typeof wcipAccount === 'undefined') {
            return;
        }

        // Handle pay button click — start polling after form submit.
        $(document).on('submit', '.wcip-pay-form', function (e) {
            var $form = $(this);
            var $card = $form.closest('.wcip-installment-card');
            var installmentId = $card.data('installment-id');

            // Show loading state on the button.
            $form.find('.wcip-pay-btn').html(wcipAccount.payingText + ' <span class="wcip-card-loading"></span>').prop('disabled', true);

            // Start polling after a short delay (let the redirect happen or fail).
            setTimeout(function () {
                startPolling(installmentId, $card);
            }, 2000);
        });

        // Handle manual retry button.
        $(document).on('click', '.wcip-retry-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var installmentId = $btn.data('installment-id');
            var $card = $btn.closest('.wcip-installment-card');

            $btn.html(wcipAccount.retryText + ' <span class="wcip-card-loading"></span>').prop('disabled', true);

            $.ajax({
                url: wcipAccount.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wcip_retry_payment',
                    nonce: wcipAccount.nonce,
                    installment_id: installmentId
                },
                success: function (response) {
                    if (response.success && response.data.payment_url) {
                        $btn.html(wcipAccount.retryText).prop('disabled', false);
                        window.location.href = response.data.payment_url;
                    } else {
                        $btn.html(wcipAccount.retryText).prop('disabled', false);
                        showCardMessage($card, 'fail', response.data.message || wcipAccount.failText);
                    }
                },
                error: function () {
                    $btn.html(wcipAccount.retryText).prop('disabled', false);
                    showCardMessage($card, 'fail', wcipAccount.failText);
                }
            });
        });

        // Modal close handlers.
        $(document).on('click', '.wcip-modal-close, .wcip-modal-close-btn', function () {
            closeModal();
        });

        // Close modal on overlay click.
        $(document).on('click', '.wcip-modal', function (e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Modal retry button.
        $(document).on('click', '.wcip-modal-retry-btn', function () {
            closeModal();
            $('.wcip-retry-btn').first().trigger('click');
        });
    }

    function startPolling(installmentId, $card) {
        if (pollTimers[installmentId]) {
            clearInterval(pollTimers[installmentId]);
        }

        var attempts = 0;

        pollTimers[installmentId] = setInterval(function () {
            attempts++;

            if (attempts > maxPollAttempts) {
                clearInterval(pollTimers[installmentId]);
                delete pollTimers[installmentId];
                showCardMessage($card, 'fail', wcipAccount.failText);
                $card.find('.wcip-retry-btn').show();
                showFailModal();
                return;
            }

            $.ajax({
                url: wcipAccount.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wcip_check_payment_status',
                    nonce: wcipAccount.nonce,
                    installment_id: installmentId
                },
                success: function (response) {
                    if (!response.success) {
                        return;
                    }

                    var data = response.data;

                    if (data.status === 'paid') {
                        clearInterval(pollTimers[installmentId]);
                        delete pollTimers[installmentId];
                        updateCardToPaid($card, data);
                        showSuccessModal(data.invoice_url);
                    } else if (data.retry_count > 0 && data.retry_count >= data.max_retries) {
                        clearInterval(pollTimers[installmentId]);
                        delete pollTimers[installmentId];
                        showCardMessage($card, 'fail', wcipAccount.failText);
                        $card.find('.wcip-retry-btn').show();
                        showFailModal();
                    }
                },
                error: function () {
                    // Network error — keep polling.
                }
            });
        }, pollInterval);
    }

    function updateCardToPaid($card, data) {
        var statusBadge = $card.find('.wcip-status-badge');
        statusBadge.removeClass('wcip-status-unpaid wcip-status-overdue')
                    .addClass('wcip-status-paid')
                    .text(wcipAccount.successText);

        $card.removeClass('wcip-status-unpaid wcip-status-overdue wcip-next-upcoming')
             .addClass('wcip-status-paid');
        $card.find('.wcip-next-badge').remove();

        // Replace pay button with invoice download link.
        $card.find('.wcip-card-actions').html(
            '<a href="' + data.invoice_url + '" class="button wcip-invoice-btn" target="_blank">' +
            wcipAccount.invoiceText + '</a>'
        );

        showCardMessage($card, 'success', wcipAccount.successText);
    }

    function showCardMessage($card, type, message) {
        var $msg = $card.find('.wcip-card-status-msg');
        $msg.removeClass('wcip-msg-success wcip-msg-fail wcip-msg-pending')
            .addClass('wcip-msg-' + type)
            .text(message)
            .slideDown(200);
    }

    function showSuccessModal(invoiceUrl) {
        var $modal = $('#wcip-success-modal');
        if (invoiceUrl) {
            $modal.find('.wcip-modal-invoice-btn').attr('href', invoiceUrl);
        }
        $modal.css('display', 'flex');
    }

    function showFailModal() {
        $('#wcip-fail-modal').css('display', 'flex');
    }

    function closeModal() {
        $('.wcip-modal').css('display', 'none');
    }

    /* ===== Notification Center — In-App Reminders ===== */

    function initNotificationCenter() {
        if (typeof wcipReminders === 'undefined') {
            return;
        }

        var $center = $('#wcip-notification-center');
        if ($center.length === 0) {
            return;
        }

        $center.show();

        var dismissed = loadDismissedReminders();
        var today = getToday();

        // Clean up old dismissals (keep only today's).
        Object.keys(dismissed).forEach(function (key) {
            if (dismissed[key] !== today) {
                delete dismissed[key];
            }
        });
        saveDismissedReminders(dismissed);

        // Fetch reminders on load.
        fetchReminders();

        // Bell button click — toggle dropdown.
        $center.on('click', '#wcip-bell-btn', function (e) {
            e.preventDefault();
            $('#wcip-reminder-dropdown').slideToggle(200);
        });

        // Close dropdown.
        $center.on('click', '.wcip-reminder-close', function () {
            $('#wcip-reminder-dropdown').slideUp(200);
        });

        // Dismiss individual reminder.
        $center.on('click', '.wcip-reminder-dismiss', function (e) {
            e.preventDefault();
            var id = $(this).data('reminder-id');
            dismissed[id] = today;
            saveDismissedReminders(dismissed);
            $(this).closest('.wcip-reminder-item').slideUp(150, function () {
                $(this).remove();
                updateBellCount();
            });
        });

        // Pay button in reminder.
        $center.on('click', '.wcip-reminder-pay-btn', function (e) {
            e.preventDefault();
            window.location.href = wcipReminders.installmentsUrl;
        });
    }

    function fetchReminders() {
        $.ajax({
            url: wcipReminders.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wcip_get_reminders',
                nonce: wcipReminders.nonce
            },
            success: function (response) {
                if (!response.success) {
                    return;
                }

                var reminders = response.data.reminders;
                var dismissed = loadDismissedReminders();
                var today = getToday();

                var activeReminders = [];
                reminders.forEach(function (r) {
                    if (dismissed[r.id] !== today) {
                        activeReminders.push(r);
                    }
                });

                renderReminders(activeReminders);
                updateBellCount(activeReminders.length);
            }
        });
    }

    function renderReminders(reminders) {
        var $list = $('#wcip-reminder-list');

        if (reminders.length === 0) {
            $list.html('<p class="wcip-reminder-empty">' + wcipReminders.i18n.noReminders + '</p>');
            return;
        }

        var html = '';
        reminders.forEach(function (r) {
            var statusClass = 'wcip-reminder-status-' + r.dynamic_status;
            var daysText = '';

            if (r.dynamic_status === 'due_today') {
                daysText = wcipReminders.i18n.dueTodayLabel;
            } else if (r.dynamic_status === 'overdue') {
                daysText = toPersian(Math.abs(r.days_diff)) + ' ' + wcipReminders.i18n.daysOverdue;
            } else if (r.dynamic_status === 'due_soon') {
                daysText = toPersian(r.days_diff) + ' ' + wcipReminders.i18n.daysLeft;
            }

            html += '<div class="wcip-reminder-item ' + statusClass + '" data-reminder-id="' + r.id + '">';
            html += '<div class="wcip-reminder-item-header">';
            html += '<span class="wcip-reminder-badge ' + statusClass + '">' + r.status_label + '</span>';
            html += '<button type="button" class="wcip-reminder-dismiss" data-reminder-id="' + r.id + '">&times;</button>';
            html += '</div>';
            html += '<div class="wcip-reminder-item-body">';
            html += '<p class="wcip-reminder-product">' + r.product_name + '</p>';
            html += '<p class="wcip-reminder-desc">' + toPersian(r.installment_num) + ' / ' + toPersian(r.total_installments) + ' — ' + r.amount + '</p>';
            html += '<p class="wcip-reminder-due">' + r.due_date + '</p>';
            if (daysText) {
                html += '<p class="wcip-reminder-days">' + daysText + '</p>';
            }
            html += '</div>';
            html += '<div class="wcip-reminder-item-actions">';
            html += '<a href="' + wcipReminders.installmentsUrl + '" class="button wcip-reminder-pay-btn">' + wcipReminders.i18n.payNow + '</a>';
            html += '</div>';
            html += '</div>';
        });

        $list.html(html);
    }

    function updateBellCount(count) {
        var $count = $('#wcip-reminder-count');
        if (count === undefined) {
            count = $('#wcip-reminder-list .wcip-reminder-item').length;
        }

        if (count > 0) {
            $count.text(toPersian(count)).show();
        } else {
            $count.hide();
        }
    }

    function loadDismissedReminders() {
        try {
            var data = localStorage.getItem('wcip_dismissed_reminders');
            return data ? JSON.parse(data) : {};
        } catch (e) {
            return {};
        }
    }

    function saveDismissedReminders(data) {
        try {
            localStorage.setItem('wcip_dismissed_reminders', JSON.stringify(data));
        } catch (e) {
            // localStorage might be unavailable.
        }
    }

    function getToday() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

})(jQuery);
