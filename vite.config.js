import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'path'

export default defineConfig({
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV)
  },
  build: {
    outDir: 'assets/js/blocks',
    emptyOutDir: true,
    lib: {
      entry: resolve(__dirname, 'src/index.tsx'),
      name: 'UPOS_Blocks',
      formats: ['iife'], // WordPress uses global scripts
      fileName: () => 'upos-blocks.js'
    },
    rollupOptions: {
      // Externalize dependencies that are provided by WordPress
      external: [
        'react',
        'react-dom',
        '@wordpress/element',
        '@wordpress/i18n',
        '@wordpress/html-entities',
        '@woocommerce/blocks-registry',
        '@woocommerce/settings'
      ],
      output: {
        // Provide global variables for externalized dependencies
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
          '@wordpress/element': 'wp.element',
          '@wordpress/i18n': 'wp.i18n',
          '@wordpress/html-entities': 'wp.htmlEntities',
          '@woocommerce/blocks-registry': 'wc.wcBlocksRegistry',
          '@woocommerce/settings': 'wc.wcSettings'
        },
      },
    },
    minify: true
  }
})
