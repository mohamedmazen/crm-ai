import js from '@eslint/js';
import reactPlugin from 'eslint-plugin-react';
import reactHooksPlugin from 'eslint-plugin-react-hooks';
import prettierConfig from 'eslint-config-prettier';

export default [
    js.configs.recommended,
    {
        files: ['resources/js/**/*.{js,jsx}'],
        plugins: {
            react: reactPlugin,
            'react-hooks': reactHooksPlugin,
        },
        languageOptions: {
            globals: {
                window:    'readonly',
                document:  'readonly',
                navigator: 'readonly',
                console:   'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                setInterval: 'readonly',
                clearInterval: 'readonly',
                requestAnimationFrame: 'readonly',
                URLSearchParams: 'readonly',
                FileReader: 'readonly',
                alert: 'readonly',
                Notification: 'readonly',
                require: 'readonly',
                FB: 'readonly',
                Event:     'readonly',
                DOMParser: 'readonly',
                fetch:     'readonly',
                AbortController: 'readonly',
                localStorage: 'readonly',
                Intl:      'readonly',
                route:     'readonly',
                confirm:   'readonly',
                FormData:  'readonly',
                Blob:      'readonly',
                URL:       'readonly',
            },
            parserOptions: {
                ecmaVersion: 2022,
                sourceType:  'module',
                ecmaFeatures: { jsx: true },
            },
        },
        settings: {
            react: { version: 'detect' },
        },
        rules: {
            ...reactPlugin.configs.recommended.rules,
            ...reactHooksPlugin.configs.recommended.rules,
            'react/prop-types': 'off',
            'react/react-in-jsx-scope': 'off',
            // Inertia's <Head> uses head-key as a stable identity attribute.
            'react/no-unknown-property': ['error', { ignore: ['head-key'] }],
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
            'no-console': 'warn',
            // Pre-existing legacy patterns flagged by the React Compiler rules.
            // Keep them visible as warnings so CI fails only on new hard errors.
            'react-hooks/set-state-in-effect': 'warn',
            'react-hooks/immutability': 'warn',
            'react-hooks/purity': 'warn',
            'react/no-unescaped-entities': 'warn',
            'no-empty': 'warn',
        },
    },
    {
        files: ['resources/js/__tests__/**/*.{js,jsx}'],
        languageOptions: {
            globals: {
                vi: 'readonly',
                describe: 'readonly',
                it: 'readonly',
                test: 'readonly',
                expect: 'readonly',
                beforeEach: 'readonly',
                afterEach: 'readonly',
                beforeAll: 'readonly',
                afterAll: 'readonly',
                global: 'readonly',
            },
        },
    },
    prettierConfig,
];
