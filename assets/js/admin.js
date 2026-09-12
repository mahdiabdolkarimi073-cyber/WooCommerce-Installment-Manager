/* ===== Admin JavaScript for WooCommerce Installment Payment v2.0 ===== */

(function ($) {
    'use strict';

    // Localized strings for SMS panel UI.
    var wsipConnectText = 'اتصال';
    var wsipLoadingText = 'در حال اتصال...';
    var wsipConnectedText = 'متصل و فعال';
    var wsipAvailableText = 'قابل اتصال';
    var wsipTestSmsText = 'ارسال پیامک آزمایشی';
    var wsipSendingText = 'در حال ارسال...';
    var wsipEnterPhoneText = 'شماره تلفن را وارد کنید.';
    var wsipErrorText = 'خطای ارتباط با سرور.';

    $(document).ready(function () {

        // Toggle custom fields visibility based on "use global" checkbox.
        function toggleCustomFields() {
            var useGlobal = $('#_wcip_use_global').is(':checked');
            if (useGlobal) {
                $('.wcip-custom-fields').slideUp(200);
            } else {
                $('.wcip-custom-fields').slideDown(200);
            }
        }

        toggleCustomFields();
        $('#_wcip_use_global').on('change', toggleCustomFields);

        // Toggle the enable checkbox visual state — dim custom fields when disabled.
        function toggleEnabledState() {
            var enabled = $('#_wcip_enabled').is(':checked');
            if (!enabled) {
                $('#wcip_product_installment_data .options_group').not(':first').css('opacity', '0.5');
            } else {
                $('#wcip_product_installment_data .options_group').css('opacity', '1');
            }
        }

        toggleEnabledState();
        $('#_wcip_enabled').on('change', toggleEnabledState);

        // --- Plans builder: add / remove rows ---
        var planIndex = 0;

        function updatePlanIndices() {
            $('#wcip-plans-body tr').each(function (i) {
                $(this).find('.wcip-plan-months').attr('name', '_wcip_plans[' + i + '][months]');
                $(this).find('.wcip-plan-interest').attr('name', '_wcip_plans[' + i + '][interest_rate]');
            });
        }

        // Add new plan row
        $('#wcip-add-plan').on('click', function () {
            var $newRow = $(
                '<tr class="wcip-plan-row">' +
                '<td><input type="number" name="_wcip_plans[0][months]" value="3" min="1" step="1" class="wcip-plan-months" /></td>' +
                '<td><input type="number" name="_wcip_plans[0][interest_rate]" value="0" min="0" step="any" class="wcip-plan-interest" /></td>' +
                '<td><button type="button" class="button wcip-remove-plan">حذف</button></td>' +
                '</tr>'
            );
            $('#wcip-plans-body').append($newRow);
            updatePlanIndices();
            $newRow.find('.wcip-plan-months').focus();
        });

        // Remove plan row (event delegation for dynamically added rows)
        $(document).on('click', '.wcip-remove-plan', function () {
            // Keep at least one row.
            if ($('#wcip-plans-body tr').length > 1) {
                $(this).closest('tr').fadeOut(150, function () {
                    $(this).remove();
                    updatePlanIndices();
                });
            } else {
                // Clear the last row instead of removing it.
                $(this).closest('tr').find('input[type="number"]').val('');
            }
        });

        // Initialize indices on load.
        updatePlanIndices();

        // ===== SMS Panel Connect Buttons =====

        $(document).on('click', '.wcip-connect-sms', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var panelId = $btn.data('panel-id');
            var nonce = $btn.data('nonce');

            $btn.prop('disabled', true).text(wsipLoadingText);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wcip_connect_sms_panel',
                    nonce: nonce,
                    panel_id: panelId
                },
                success: function (response) {
                    if (response.success) {
                        // Update all badges in the connectable table.
                        $('.wcip-sms-connectable .wcip-badge').removeClass('wcip-badge-connected')
                            .addClass('wcip-badge-available')
                            .text(wsipAvailableText);
                        // Mark this row as connected.
                        $btn.closest('tr').find('.wcip-badge')
                            .removeClass('wcip-badge-available')
                            .addClass('wcip-badge-connected')
                            .text(wsipConnectedText);
                        // Show success message.
                        $('.wcip-test-sms-result').removeClass('wcip-sms-test-error')
                            .addClass('wcip-sms-test-success')
                            .text(response.data.message)
                            .show();
                    } else {
                        $('.wcip-test-sms-result').removeClass('wcip-sms-test-success')
                            .addClass('wcip-sms-test-error')
                            .text(response.data.message || wsipErrorText)
                            .show();
                    }
                },
                error: function () {
                    $('.wcip-test-sms-result').removeClass('wcip-sms-test-success')
                        .addClass('wcip-sms-test-error')
                        .text(wsipErrorText)
                        .show();
                },
                complete: function () {
                    $btn.prop('disabled', false).text(wsipConnectText);
                }
            });
        });

        // ===== SMS Test Button =====

        $(document).on('click', '.wcip-send-test-sms', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var phone = $('#wcip-test-sms-phone').val();
            var $msg = $('.wcip-test-sms-result');

            if (!phone) {
                $msg.removeClass('wcip-sms-test-success wcip-sms-test-error')
                    .addClass('wcip-sms-test-error')
                    .text(wsipEnterPhoneText)
                    .show();
                return;
            }

            $btn.prop('disabled', true).text(wsipSendingText);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wcip_send_test_sms',
                    nonce: $btn.data('nonce'),
                    phone: phone
                },
                success: function (response) {
                    if (response.success) {
                        $msg.removeClass('wcip-sms-test-error')
                            .addClass('wcip-sms-test-success')
                            .text(response.data.message)
                            .show();
                    } else {
                        $msg.removeClass('wcip-sms-test-success')
                            .addClass('wcip-sms-test-error')
                            .text(response.data.message || wsipErrorText)
                            .show();
                    }
                },
                error: function () {
                    $msg.removeClass('wcip-sms-test-success')
                        .addClass('wcip-sms-test-error')
                        .text(wsipErrorText)
                        .show();
                },
                complete: function () {
                    $btn.prop('disabled', false).text(wsipTestSmsText);
                }
            });
        });

        // ===== Installments management: delete confirmation =====

        // Single delete confirmation prompt.
        $(document).on('click', '.wcip-delete-installment', function (e) {
            var message = (window.wcipAdmin && wcipAdmin.confirmDelete) ? wcipAdmin.confirmDelete : 'آیا مطمئن هستید؟';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });

        // Bulk delete confirmation.
        $(document).on('submit', '.wcip-admin-installments-wrap form', function (e) {
            var $form = $(this);
            var action = $form.find('select[name="action"], select[name="action2"]').filter(':visible').val();

            if (action === 'delete') {
                var checked = $form.find('input[name="installment[]"]:checked').length;
                if (checked === 0) {
                    e.preventDefault();
                    return false;
                }
                var message = (window.wcipAdmin && wcipAdmin.confirmDelete) ? wcipAdmin.confirmDelete : 'آیا مطمئن هستید؟';
                if (!confirm(message)) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    });

})(jQuery);
