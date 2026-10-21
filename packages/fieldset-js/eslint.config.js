import js from '@eslint/js';
import { defineConfig } from 'eslint/config';
import prettier from 'eslint-config-prettier';
import tseslint from 'typescript-eslint';

export default defineConfig(
    { ignores: ['coverage/**', 'dist/**', 'node_modules/**'] },
    js.configs.recommended,
    tseslint.configs.strictTypeChecked,
    {
        languageOptions: {
            parserOptions: { projectService: true, tsconfigRootDir: import.meta.dirname },
        },
    },
    {
        files: ['src/**/*.ts'],
        rules: {
            'no-restricted-imports': [
                'error',
                { patterns: [{ group: ['node:*'], message: 'src/ must stay browser-safe.' }] },
            ],
            'max-lines': ['error', { max: 400, skipBlankLines: true, skipComments: true }],
            'max-depth': ['error', 4],
            'max-lines-per-function': [
                'error',
                { max: 60, skipBlankLines: true, skipComments: true },
            ],
        },
    },
    { files: ['**/*.js'], extends: [tseslint.configs.disableTypeChecked] },
    prettier,
);
