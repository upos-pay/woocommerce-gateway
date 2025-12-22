import eslint from '@eslint/js'
import stylistic from '@stylistic/eslint-plugin'
import tseParser from '@typescript-eslint/parser'
import esImport from 'eslint-plugin-import'
import perfectionist from 'eslint-plugin-perfectionist'
import tseslint from 'typescript-eslint'
import reactRefresh from 'eslint-plugin-react-refresh'
import reactHooks from 'eslint-plugin-react-hooks'
import react from 'eslint-plugin-react'
import html from 'eslint-plugin-html'
import globals from 'globals'

const project = ['./tsconfig.json', './tsconfig.node.json']

export default [
  eslint.configs.recommended,
  stylistic.configs.recommended,
  ...tseslint.configs.recommended,
  ...tseslint.configs.stylistic,
  {
    files: ['**/*.js', '**/*.cjs', '**/*.ts', '**/*.tsx']
  },
  {
    ignores: [
      '**/node_modules/**/*',
      '**/*.css.d.ts',
      '**/*.scss.d.ts',
      '**/*.config.js',
      'scripts/**/*',
      'assets/js/blocks/**/*'
    ]
  },
  {
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      parser: tseParser,
      parserOptions: {
        tsconfigRootDir: import.meta.dirname,
        project,
        ecmaFeatures: {
          jsx: true,
          module: true,
          legacyDecorators: true
        }
      },
      globals: {
        ...globals.browser,
        ...globals.node,
        log: 'readonly',
        jQuery: 'readonly',
        $: 'readonly',
        wp: 'readonly',
        upos_params: 'readonly',
        upos_admin_params: 'readonly'
      }
    },
    settings: {
      'import/resolver': {
        node: {
          extensions: ['.js', '.jsx', '.ts', '.tsx', '.css', '.scss']
        },
        alias: {
          map: [],
          extensions: ['.js', '.ts', '.tsx']
        }
      },
      react: {
        version: 'detect'
      }
    },
    rules: {
      'arrow-body-style': 'off',
      'class-methods-use-this': 'off',
      'curly': ['error', 'all'],
      'max-classes-per-file': 'off',
      'no-bitwise': 'off',
      'no-console': 'off',
      'no-continue': 'off',
      'no-else-return': 'warn',
      'no-empty': 'warn',
      'no-empty-pattern': 'warn',
      'no-nested-ternary': 'off',
      'no-param-reassign': 'warn',
      'no-restricted-exports': 'off',
      'no-restricted-syntax': 'off',
      'no-unused-vars': 'off',
      'no-underscore-dangle': 'off',
      'no-use-before-define': 'off',
      'no-useless-constructor': 'off',
      'no-var': 'warn',
      'prefer-arrow-callback': 'off',
      'prefer-destructuring': 'off',
      'no-param-reassign': ['error', {
        props: true,
        ignorePropertyModificationsForRegex: ['^draft']
      }]
    }
  },
  {
    plugins: {
      import: esImport
    },
    rules: {
      'import/extensions': ['error', 'never', {
        ignorePackages: true,
        pattern: {
          css: 'always',
          scss: 'always'
        }
      }],
      'import/no-absolute-path': 'off',
      'import/no-extraneous-dependencies': 'off',
      'import/no-unresolved': ['error', { caseSensitive: true, ignore: ['\\.svg\\?react$', '^@woocommerce/'] }],
      'import/prefer-default-export': 'off'
    }
  },
  {
    plugins: {
      '@stylistic': stylistic
    },
    rules: {
      '@stylistic/arrow-parens': ['error', 'always'],
      '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: true }],
      '@stylistic/comma-dangle': ['error', 'never'],
      '@stylistic/indent': ['error', 2, {
        SwitchCase: 1,
        MemberExpression: 1,
        flatTernaryExpressions: false,
        offsetTernaryExpressions: true,
        ObjectExpression: 1,
        VariableDeclarator: 1,
        ignoredNodes: [
          'TSUnionType',
          'TSIntersectionType',
          'TSTypeLiteral',
          'TSMappedType'
        ]
      }],
      '@stylistic/indent-binary-ops': 'off',
      '@stylistic/implicit-arrow-linebreak': 'off',
      '@stylistic/lines-between-class-members': 'off',
      '@stylistic/object-curly-newline': ['error', { multiline: true, consistent: true }],
      '@stylistic/operator-linebreak': ['error', 'before', {
        overrides: {
          '?': 'before',
          ':': 'before'
        }
      }],
      '@stylistic/max-len': 'off',
      '@stylistic/max-statements-per-line': 'off',
      '@stylistic/multiline-ternary': 'off',
      '@stylistic/no-confusing-arrow': 'off',
      '@stylistic/no-multiple-empty-lines': 'off',
      '@stylistic/padded-blocks': ['off', 'never'],
      '@stylistic/quotes': [
      'error',
      'single',
      {
        avoidEscape: true,
        allowTemplateLiterals: 'always'
      }
      ],
      '@stylistic/semi': ['error', 'never']
    }
  },
  {
    plugins: {
      tseslint
    },
    rules: {
      '@typescript-eslint/no-empty-object-type': 'off',
      '@typescript-eslint/class-literal-property-style': 'off',
      '@typescript-eslint/consistent-indexed-object-style': 'off',
      '@typescript-eslint/naming-convention': 'off',
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-empty-interface': 'off',
      '@typescript-eslint/no-empty-function': 'off',
      '@typescript-eslint/no-extraneous-class': 'off',
      '@typescript-eslint/no-non-null-assertion': 'off',
      '@typescript-eslint/no-unused-vars': 'off',
      '@typescript-eslint/no-useless-constructor': 'warn',
      '@typescript-eslint/no-throw-literal': 'off',
      '@typescript-eslint/no-use-before-define': 'off',
      '@typescript-eslint/no-unused-expressions': [
        'error',
        {
          allowShortCircuit: true,
          allowTaggedTemplates: true
        }
      ],
      '@typescript-eslint/prefer-for-of': 'off',
      '@typescript-eslint/return-await': 'off'
    }
  },
  {
    plugins: {
      perfectionist
    },
    rules: {
      'perfectionist/sort-objects': 'off',
      'perfectionist/sort-object-types': 'off',
      'perfectionist/sort-interfaces': 'off',
      'perfectionist/sort-svelte-attributes': 'off',
      'perfectionist/sort-classes': 'off',
      'perfectionist/sort-enums': 'off',
      'perfectionist/sort-union-types': [
        'error',
        {
          type: 'natural',
          order: 'asc',
          groups: [
            'conditional',
            'function',
            'import',
            'intersection',
            'keyword',
            'literal',
            'named',
            'object',
            'operator',
            'tuple',
            'union',
            'nullish',
            'unknown'
          ]
        }
      ],
      'perfectionist/sort-imports': [
        'error',
        {
          type: 'natural',
          order: 'asc',
          groups: [
            'type-import',
            ['value-builtin', 'value-external'],
            'side-effect',
            'type-internal',
            'value-internal',
            ['type-parent', 'type-sibling', 'type-index'],
            ['value-parent', 'value-sibling', 'value-index'],
            'ts-equals-import',
            'style',
            'unknown'
          ],
          newlinesBetween: 1,
          internalPattern: [
            '@src/*'
          ]
        }
      ]
    }
  },
  {
    plugins: {
      'react-refresh': reactRefresh
    },
    rules: {
      'react-refresh/only-export-components': [
        'warn',
        { allowConstantExport: true }
      ]
    }
  },
  {
    plugins: {
      react
    },
    rules: {
      'react/require-default-props': 'off',
      'react/prop-types': 'off',
      'react/react-in-jsx-scope': 'off',
      'react/self-closing-comp': 'off',
      'react/static-property-placement': 'off',
      'react/prefer-stateless-function': 'off',
      'react/function-component-definition': [1, {
        namedComponents: ['function-declaration', 'function-expression', 'arrow-function'],
        unnamedComponents: ['function-expression', 'arrow-function']
      }],
      'react/sort-comp': 'off',
      'react/no-unused-prop-types': 'warn',
      'react/jsx-filename-extension': ['warn', { extensions: ['.ts', '.tsx'] }],
      'react/destructuring-assignment': ['off', 'never'],
      'react/jsx-props-no-spreading': 'off',
      'react/forbid-prop-types': 'off',
      'react/jsx-one-expression-per-line': 'off',
      'react-refresh/only-export-components': [
        'warn',
        { allowConstantExport: true }
      ]
    }
  },
  {
    plugins: {
      'react-hooks': reactHooks
    },
    rules: {
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/exhaustive-deps': 'warn'
    }
  },
  {
    plugins: {
      html
    },
    rules: {

    }
  }
]
