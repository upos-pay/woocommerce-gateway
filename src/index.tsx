import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { getSetting } from '@woocommerce/settings'
import { useState, useEffect, useCallback } from '@wordpress/element'
import { decodeEntities } from '@wordpress/html-entities'
import { __ } from '@wordpress/i18n'

import './style.css'

interface Currency {
  id: string
  name: string
  networks: Network[]
}

interface Network {
  id: string
  name: string
}

interface UposSettings {
  title: string
  ajax_url: string
  nonce: string
  plugin_url: string
  testmode: boolean
  supports: string[]
}

interface ContentProps {
  eventRegistration?: {
    onPaymentSetup: (callback: () => { type: string, message?: string, paymentMethodData?: { key: string, value: string }[] }) => () => void
  }
  emitResponse?: {
    responseTypes: {
      ERROR: string
      SUCCESS: string
    }
  }
}

const settings: UposSettings = getSetting('upos_data', {}) as UposSettings

const Content = (props: ContentProps) => {
  const { eventRegistration, emitResponse } = props
  const { onPaymentSetup } = eventRegistration || {}
  const { SUCCESS, ERROR } = emitResponse?.responseTypes || {}

  const [currencies, setCurrencies] = useState<Currency[]>([])
  const [loading, setLoading] = useState<boolean>(true)
  const [error, setError] = useState<string | null>(null)
  const [selectedCurrency, setSelectedCurrency] = useState<string>('')
  const [selectedNetwork, setSelectedNetwork] = useState<string>('')

  useEffect(
    () => {
      // Use WordPress AJAX to fetch currencies
      // This solves CORS issues and network topology (docker vs localhost)
      const formData = new FormData()
      formData.append('action', 'upos_get_currencies')
      formData.append('nonce', settings.nonce)

      fetch(settings.ajax_url, {
        method: 'POST',
        body: formData
      })
        .then((res) => {
          if (!res.ok) {
            throw new Error(res.statusText)
          }
          return res.json()
        })
        .then((response) => {
          if (response.success && response.data && Array.isArray(response.data.currencies)) {
            const data = response.data
            setCurrencies(data.currencies)
            // Auto-select if only one currency
            if (data.currencies.length === 1) {
              const defaultCurrency = data.currencies[0].id
              setSelectedCurrency(defaultCurrency)
              // Auto-select network if only one network for the default currency
              const defaultNetwork = data.currencies[0].networks[0]?.id
              if (defaultNetwork) {
                setSelectedNetwork(defaultNetwork)
              }
            }
          } else {
            setError(response.data?.message || __('Failed to load currencies', 'upos-woocommerce'))
          }
        })
        .catch((err: Error) => { // Type the error object
          setError(err.message)
        })
        .finally(() => {
          setLoading(false)
        })
    },
    []
  )

  const onPaymentSetupCallback = useCallback(
    () => {
      if (!selectedCurrency || !selectedNetwork) {
        return {
          type: ERROR || 'error',
          message: __('Please select a currency and network', 'upos-woocommerce')
        }
      }

      const response = {
        type: SUCCESS || 'success',
        meta: {
          paymentMethodData: {
            upos_currency: selectedCurrency,
            upos_network: selectedNetwork
          }
        }
      }

      return response
    },
    [SUCCESS, ERROR, selectedCurrency, selectedNetwork]
  )

  useEffect(
    () => {
      if (!onPaymentSetup) { return () => {} }

      const unsubscribe = onPaymentSetup(onPaymentSetupCallback)
      return () => {
        unsubscribe()
      }
    },
    [onPaymentSetup, onPaymentSetupCallback]
  )

  const handleCurrencyChange = useCallback(
    (currencyId: string) => {
      setSelectedCurrency(currencyId)
      setSelectedNetwork('')

      // auto-select first network
      const currency = currencies.find((c) => c.id === currencyId)
      if (currency && currency.networks.length) {
        setSelectedNetwork(currency.networks[0].id)
      }
    },
    [currencies]
  )

  if (loading) {
    return <div>{ __('Loading payment options...', 'upos-woocommerce') }</div>
  }

  if (error) {
    return <div className="upos-error">{ error }</div>
  }

  return (
    <div className="upos-block-content">
      {
        settings.testmode && (
          <div className="upos-testmode-notice">
            { __('Test Mode Enabled', 'upos-woocommerce') }
          </div>
        )
      }

      <div className="upos-section">
        <label>{ __('Select Currency', 'upos-woocommerce') }</label>
        <div className="upos-radio-group">
          {
            currencies.map((currency: Currency) => (
              <div key={currency.id} className="upos-radio-item">
                <input
                  type="radio"
                  id={`upos-curr-${currency.id}`}
                  name="upos_currency"
                  value={currency.id}
                  checked={selectedCurrency === currency.id}
                  onChange={() => handleCurrencyChange(currency.id)}
                />
                <label htmlFor={`upos-curr-${currency.id}`} className="upos-radio-label">
                  { settings.plugin_url && (
                    <img
                      src={`${settings.plugin_url}assets/images/${currency.id.toLowerCase()}.png`}
                      alt={currency.name}
                      className="upos-icon"
                    />
                  ) }
                  <span>{ currency.name }</span>
                </label>
              </div>
            ))
          }
        </div>
      </div>

      {
        selectedCurrency && (
          <div className="upos-section">
            <label>{ __('Select Network', 'upos-woocommerce') }</label>
            <div className="upos-radio-group">
              { currencies
                .find((c) => c.id === selectedCurrency)
                ?.networks.map((network: Network) => (
                  <div key={network.id} className="upos-radio-item">
                    <input
                      type="radio"
                      id={`upos-net-${network.id}`}
                      name="upos_network"
                      value={network.id}
                      checked={selectedNetwork === network.id}
                      onChange={() => setSelectedNetwork(network.id)}
                    />
                    <label htmlFor={`upos-net-${network.id}`} className="upos-radio-label">
                      { settings.plugin_url && (
                        <img
                          src={`${settings.plugin_url}assets/images/${network.id.toLowerCase()}.png`}
                          alt={network.name}
                          className="upos-icon"
                        />
                      ) }
                      <span>{ network.name }</span>
                    </label>
                  </div>
                )) }
            </div>
          </div>
        )
      }
    </div>
  )
}

const label = (
  <>
    <span>{ decodeEntities(settings.title) || __('UPOS Payments', 'upos-woocommerce') }</span>
    { settings.plugin_url && (
      <img
        src={`${settings.plugin_url}assets/icon.svg`}
        alt={decodeEntities(settings.title) || 'UPOS Payments'}
        className="upos-main-logo"
      />
    ) }
  </>
)

registerPaymentMethod({
  name: 'upos',
  label: label,
  ariaLabel: decodeEntities(settings.title) || __('UPOS Payments', 'upos-woocommerce'), // ariaLabel should remain plain text
  content: <Content />,
  edit: <Content />, // Show same content in editor for preview
  canMakePayment: () => true,
  supports: settings.supports
})
