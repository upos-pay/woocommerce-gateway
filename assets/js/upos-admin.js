/**
 * UPOS Admin Scripts
 *
 * Handles backend settings page functionality.
 */

(function ($) {
  'use strict'

  $(document).ready(function () {
    console.log('UPOS Admin JS Loaded')

    // Scoped container for all our settings
    const $wrapper = $('.wc-upos-settings')
    if (!$wrapper.length) {
      // If wrapper not found (e.g. on other pages or if markup is broken), bail out to avoid errors
      return
    }

    const $button = $wrapper.find('.upos-test-connection')
    const $result = $wrapper.find('.upos-test-result')

    // WooCommerce inputs still use IDs as per WC Settings API standard.
    // We target them by ID for precision, as they are unique on the page.
    const $pkInput = $('#woocommerce_upos_public_key')
    const $skInput = $('#woocommerce_upos_secret_key')
    const $inputs = $pkInput.add($skInput)
    let changed = false

    // Helper to show error below input
    function showInputError($input, message) {
      // Check if error already exists
      const $container = $input.closest('td')
      const errorId = $input.attr('id') + '-error'

      if ($('#' + errorId).length === 0) {
        $container.append('<p id="' + errorId + '" class="upos-field-error" style="color: #cc0000; margin-top: 5px; font-size: 0.9em;">' + message + '</p>')
      }
      $input.css('border-color', '#cc0000')
    }

    // Helper to clear errors
    function clearErrors() {
      $('.upos-field-error').remove()
      $inputs.css('border-color', '')
      $result.empty()
    }

    // Function to validate key format and environment
    function validateKeys() {
      const pk = $pkInput.val()
      const sk = $skInput.val()
      let hasError = false

      clearErrors()

      if (!pk && !sk) { // No keys entered, assuming stored
        return true
      }

      // --- Format Validation ---
      if (pk && !(pk.startsWith('pk_test_') || pk.startsWith('pk_live_'))) {
        showInputError($pkInput, upos_admin_params.i18n.pk_format_error)
        hasError = true
      }
      if (sk && !(sk.startsWith('sk_test_') || sk.startsWith('sk_live_'))) {
        showInputError($skInput, upos_admin_params.i18n.sk_format_error)
        hasError = true
      }

      // --- Environment Consistency Validation ---
      if (!hasError) {
        const isPkTest = pk.startsWith('pk_test_')
        const isSkTest = sk.startsWith('sk_test_')
        const isPkLive = pk.startsWith('pk_live_')
        const isSkLive = sk.startsWith('sk_live_')

        const pkEnvSet = isPkTest || isPkLive
        const skEnvSet = isSkTest || isSkLive

        if (pkEnvSet && skEnvSet) {
          if ((isPkTest !== isSkTest) || (isPkLive !== isSkLive)) {
            const mismatchMsg = upos_admin_params.i18n.env_mismatch_error
            showInputError($pkInput, mismatchMsg)
            showInputError($skInput, mismatchMsg)
            hasError = true
          }
        }
      }

      return !hasError
    }

    // Detect changes in input fields
    $inputs.on('input change', function () {
      if (!changed) {
        console.log('Input changed, disabling test button')
        changed = true
        $button.prop('disabled', true)
        $result.css('color', '#856404').text(upos_admin_params.i18n.save_first)
      }
      validateKeys()
    })

    // Test Connection Button
    $button.on('click', function (e) {
      e.preventDefault()
      console.log('Test Connection Button Clicked')

      if (changed) {
        console.log('Settings changed, aborting test')
        return
      }

      if (!validateKeys()) {
        $button.prop('disabled', false)
        return
      }

      $button.prop('disabled', true)
      $result.css('color', '#666').text(upos_admin_params.i18n.testing)

      $.ajax({
        url: upos_admin_params.ajax_url,
        type: 'POST',
        data: {
          action: 'upos_test_connection',
          nonce: upos_admin_params.nonce
        },
        success: function (response) {
          console.log('AJAX Success:', response)
          if (response.success) {
            $result.css('color', '#008000').text(response.data.message)
          } else {
            const msg = (response.data && response.data.message) ? response.data.message : upos_admin_params.i18n.error
            $result.css('color', '#cc0000').text(msg)
          }
        },
        error: function (xhr, status, error) {
          console.error('AJAX Error:', status, error, xhr.responseText)
          $result.css('color', '#cc0000').text(upos_admin_params.i18n.error)
        },
        complete: function () {
          console.log('AJAX Complete')
          if (!changed) {
            $button.prop('disabled', false)
          }
        }
      })
    })

    // Manual Sync Button
    $wrapper.find('.upos-manual-sync').on('click', function (e) {
      e.preventDefault()
      const $btn = $(this)
      const $res = $wrapper.find('.upos-sync-result')

      $btn.prop('disabled', true)
      $res.css('color', '#666').text(upos_admin_params.i18n.processing)

      $.ajax({
        url: upos_admin_params.ajax_url,
        type: 'POST',
        data: {
          action: 'upos_manual_sync',
          nonce: upos_admin_params.nonce_sync
        },
        success: function (response) {
          if (response.success) {
            $res.css('color', '#008000').text(response.data.message)
            if (response.data.last_run) {
              // Update the text within the scoped wrapper
              $wrapper.find('.upos-last-run-sync').text(response.data.last_run)
            }
          } else {
            $res.css('color', '#cc0000').text(response.data.message)
          }
        },
        error: function () {
          $res.css('color', '#cc0000').text(upos_admin_params.i18n.error)
        },
        complete: function () {
          setTimeout(function () {
            $btn.prop('disabled', false) // Simple UI throttle reset, real lock is backend
          }, 2000)
        }
      })
    })

    // Manual Expire Button
    $wrapper.find('.upos-manual-expire').on('click', function (e) {
      e.preventDefault()
      const $btn = $(this)
      const $res = $wrapper.find('.upos-expire-result')

      $btn.prop('disabled', true)
      $res.css('color', '#666').text(upos_admin_params.i18n.processing)

      $.ajax({
        url: upos_admin_params.ajax_url,
        type: 'POST',
        data: {
          action: 'upos_manual_expire',
          nonce: upos_admin_params.nonce_expire
        },
        success: function (response) {
          if (response.success) {
            $res.css('color', '#008000').text(response.data.message)
            if (response.data.last_run) {
              // Update the text within the scoped wrapper
              $wrapper.find('.upos-last-run-expire').text(response.data.last_run)
            }
          } else {
            $res.css('color', '#cc0000').text(response.data.message)
          }
        },
        error: function () {
          $res.css('color', '#cc0000').text(upos_admin_params.i18n.error)
        },
        complete: function () {
          setTimeout(function () {
            $btn.prop('disabled', false)
          }, 2000)
        }
      })
    })

  })

})(jQuery)
