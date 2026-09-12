/* ===== Admin JavaScript for WooCommerce Installment Payment v2.0 ===== */

(function ($) {
    'use strict';

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

        // ===== SMS Test Button =====

        $('#wcip-sms-test-btn').on('click', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var phone = $('#wcip_sms_test_phone').val();
            var $msg = $('#wcip-sms-test-result');

            if (!phone) {
                $msg.removeClass('wcip-sms-test-success wcip-sms-test-error')
                    .addClass('wcip-sms-test-error')
                    .text('شماره تلفن را وارد کنید.')
                    .show();
                return;
            }

            $btn.prop('disabled', true).text('در حال ارسال...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wcip_send_test_sms',
                    nonce: (typeof wcipAdmin !== 'undefined') ? wcipAdmin.nonce : '',
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
                            .text(response.data.message || 'خطا در ارسال پیامک.')
                            .show();
                    }
                },
                error: function () {
                    $msg.removeClass('wcip-sms-test-success')
                        .addClass('wcip-sms-test-error')
                        .text('خطای ارتباط با سرور.')
                        .show();
                },
                complete: function () {
                    $btn.prop('disabled', false).text('ارسال پیامک آزمایشی');
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
