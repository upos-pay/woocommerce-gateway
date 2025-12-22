/**
 * UPOS Checkout Scripts
 *
 * Handles frontend checkout functionality.
 * Uses public key for direct API calls to get supported currencies.
 */

(function ($) {
  'use strict'

  const UPOS_Checkout = {
    /**
     * Cached supported currencies data
     */
    supportedCurrencies: null,

    /**
     * Initialize
     */
    init: function () {
      this.bindEvents()
    },

    /**
     * Bind event handlers
     */
    bindEvents: function () {
      // Load payment options when UPOS payment is selected
      $(document.body).on('payment_method_selected', this.onPaymentMethodSelected.bind(this))
      $(document.body).on('updated_checkout', this.onCheckoutUpdated.bind(this))

      // Handle currency/network selection changes for radio buttons
      $(document).on('change', 'input[name="upos_currency_radio"]', this.onCurrencyChange.bind(this))
      $(document).on('change', 'input[name="upos_network_radio"]', this.onNetworkChange.bind(this))
    },

    /**
     * Handle payment method selection
     */
    onPaymentMethodSelected: function () {
      if ($('#payment_method_upos').is(':checked')) {
        this.loadPaymentOptions()
      }
    },

    /**
     * Handle checkout update
     */
    onCheckoutUpdated: function () {
      if ($('#payment_method_upos').is(':checked')) {
        this.loadPaymentOptions()
      }
    },

    /**
     * Load payment options via WordPress AJAX
     */
    loadPaymentOptions: function () {
      const $container = $('.upos-options-render-target')
      
      // If already loaded, just render
      if (this.supportedCurrencies) {
        this.renderPaymentOptions()
        this.updateHiddenFieldsFromRadios() // Ensure hidden fields are updated on re-render
        return
      }

      // Show loading state
      $container.html('<p>' + upos_params.i18n.loading + '</p>')

      // Fetch supported currencies via WP AJAX (Server-to-Server)
      $.ajax({
        url: upos_params.ajax_url,
        type: 'POST',
        data: {
          action: 'upos_get_currencies',
          nonce: upos_params.nonce
        },
        success: function (response) {
          if (response.success && response.data && Array.isArray(response.data.currencies)) {
            UPOS_Checkout.supportedCurrencies = response.data.currencies
            UPOS_Checkout.renderPaymentOptions()
            UPOS_Checkout.updateHiddenFieldsFromRadios()
          } else {
            let errorMsg = upos_params.i18n.error
            if (response.data && response.data.message) {
              errorMsg = response.data.message
            }
            $container.html('<p class="upos-error">' + errorMsg + '</p>')
          }
        },
        error: function (xhr) {
          $container.html('<p class="upos-error">' + upos_params.i18n.error + '</p>')
        }
      })
    },

    /**
     * Render payment option radio buttons
     *
     * Currencies format: [{ id: "usdt", name: "USDT", networks: [{ id: "tron", name: "TRON" }] }]
     */
    renderPaymentOptions: function () {
      const $container = $('.upos-options-render-target')
      const currencies = this.supportedCurrencies

      if (!currencies || !Array.isArray(currencies) || currencies.length === 0) {
        $container.html('<p class="upos-error">' + upos_params.i18n.error + '</p>')
        return
      }

      let html = '<div class="upos-payment-options-wrapper">'

      // Currency radio buttons
      html += '<div class="upos-section upos-currency-section">'
      html += '<label>' + upos_params.i18n.select_currency + '</label>'
      html += '<div class="upos-radio-group upos-currency-group">'

      currencies.forEach(function (currency) {
        const iconSrc = upos_params.plugin_url + 'assets/images/' + currency.id.toLowerCase() + '.png'
        html += '<div class="upos-radio-item">'
        html += '<input type="radio" id="upos_currency_' + currency.id + '" name="upos_currency_radio" value="' + currency.id + '" />'
        html += '<label for="upos_currency_' + currency.id + '" class="upos-radio-label">'
        html += '<img src="' + iconSrc + '" alt="' + currency.name + '" class="upos-icon" />'
        html += '<span>' + currency.name + '</span>'
        html += '</label>'
        html += '</div>'
      })
      html += '</div>' // .upos-radio-group
      html += '</div>' // .upos-section

      // Network radio buttons (initially hidden/disabled)
      html += '<div class="upos-section upos-network-section" style="display:none;">'
      html += '<label>' + upos_params.i18n.select_network + '</label>'
      html += '<div class="upos-radio-group upos-network-group">'
      // Network options will be dynamically loaded here
      html += '</div>'
      html += '</div>'

      html += '</div>' // .upos-payment-options-wrapper

      $container.html(html)

      // Auto-select if only one currency
      if (currencies.length === 1) {
        $container.find('input[name="upos_currency_radio"][value="' + currencies[0].id + '"]')
          .prop('checked', true)
          .trigger('change')
      }
    },

    /**
     * Handle currency selection change
     *
     * Find the selected currency object and get its networks array
     */
    onCurrencyChange: function (e) {
      const $target = $(e.target)
      const selectedCurrencyId = $target.val()

      // Find relative elements
      const $container = $target.closest('.upos-shortcode-content')
      const $networkSection = $container.find('.upos-network-section')
      const $networkGroup = $container.find('.upos-network-group')

      // Update hidden field for currency (Global fields, kept as ID for now as PHP expects them)
      $('#upos_currency').val(selectedCurrencyId)
      $('#upos_network').val('') // Clear network when currency changes

      if (!selectedCurrencyId) {
        $networkSection.hide()
        $networkGroup.html('')
        return
      }

      // Find the selected currency object to get its networks
      let selectedCurrency = null
      this.supportedCurrencies.forEach(function (currency) {
        if (currency.id === selectedCurrencyId) {
          selectedCurrency = currency
        }
      })

      if (!selectedCurrency || !selectedCurrency.networks || selectedCurrency.networks.length === 0) {
        $networkSection.hide()
        $networkGroup.html('')
        return
      }

      // Build network options from the currency's networks array
      let networkHtml = ''
      selectedCurrency.networks.forEach(function (network) {
        const iconSrc = upos_params.plugin_url + 'assets/images/' + network.id.toLowerCase() + '.png'

        networkHtml += '<div class="upos-radio-item">'
        networkHtml += '<input type="radio" id="upos_network_' + network.id + '" name="upos_network_radio" value="' + network.id + '" />'
        networkHtml += '<label for="upos_network_' + network.id + '" class="upos-radio-label">'
        networkHtml += '<img src="' + iconSrc + '" alt="' + network.name + '" class="upos-icon" />'
        networkHtml += '<span>' + network.name + '</span>'
        networkHtml += '</label>'
        networkHtml += '</div>'
      })

      $networkGroup.html(networkHtml)
      $networkSection.show()

      // Auto-select if only one network
      if (selectedCurrency.networks.length === 1) {
        $networkGroup.find('input[name="upos_network_radio"][value="' + selectedCurrency.networks[0].id + '"]').prop('checked', true).trigger('change')
      }
    },

    /**
     * Handle network selection change
     */
    onNetworkChange: function (e) {
      const selectedNetwork = $(e.target).val()
      $('#upos_network').val(selectedNetwork)
    },

    /**
     * Update hidden fields from selected radio buttons
     */
    updateHiddenFieldsFromRadios: function () {
      const selectedCurrency = $('input[name="upos_currency_radio"]:checked').val()
      const selectedNetwork = $('input[name="upos_network_radio"]:checked').val()

      $('#upos_currency').val(selectedCurrency || '')
      $('#upos_network').val(selectedNetwork || '')
    }
  }

  $(document).ready(function () {
    UPOS_Checkout.init()
  })

})(jQuery)
