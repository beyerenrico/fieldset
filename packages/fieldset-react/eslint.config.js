import js from '@eslint/js';
import { defineConfig } from 'eslint/config';
import prettier from 'eslint-config-prettier';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default defineConfig(
    { ignores: ['coverage/**', 'dist/**', 'node_modules/**'] },
    js.configs.recommended,
    tseslint.configs.strictTypeChecked,
    reactHooks.configs.flat.recommended,
    {
        languageOptions: {
            globals: globals.browser,
            parserOptions: { projectService: true, tsconfigRootDir: import.meta.dirname },
        },
    },
    {
        files: ['src/**/*.{ts,tsx}'],
        rules: {
            'no-restricted-imports': [
                'error',
                { patterns: [{ group: ['node:*'], message: 'src/ must stay browser-safe.' }] },
            ],
            'no-restricted-globals': [
                'error',
                { name: 'fetch', message: 'The renderer makes no network calls; use the adapter.' },
                { name: 'XMLHttpRequest', message: 'The renderer makes no network calls.' },
            ],
            'max-lines': ['error', { max: 300, skipBlankLines: true, skipComments: true }],
            'max-depth': ['error', 4],
        },
    },
    {
        files: ['test/**/*.{ts,tsx}'],
        rules: {
            '@typescript-eslint/no-non-null-assertion': 'off',
            'max-lines': ['error', { max: 400, skipBlankLines: true, skipComments: true }],
        },
    },
    { files: ['**/*.js'], extends: [tseslint.configs.disableTypeChecked] },
    prettier,
);
