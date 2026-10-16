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
            'max-lines': ['error', { max: 300, skipBlankLines: true, skipComments: true }],
            'max-depth': ['error', 4],
            'no-console': ['error', { allow: ['warn', 'error'] }],
            // TanStack Router control flow: `throw redirect(...)` / `throw notFound()`.
            '@typescript-eslint/only-throw-error': [
                'error',
                {
                    allow: [
                        { from: 'package', package: '@tanstack/router-core', name: 'Redirect' },
                    ],
                },
            ],
        },
    },
    {
        files: ['test/**/*.{ts,tsx}'],
        rules: {
            '@typescript-eslint/no-non-null-assertion': 'off',
            'max-lines': ['error', { max: 400, skipBlankLines: true, skipComments: true }],
        },
    },
    {
        files: ['*.config.ts', '**/*.js'],
        extends: [tseslint.configs.disableTypeChecked],
        languageOptions: { globals: globals.node },
    },
    prettier,
);
