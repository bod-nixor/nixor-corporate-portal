/** .eslintrc.cjs */
module.exports = {
  root: true,
  parser: '@typescript-eslint/parser',
  plugins: ['@typescript-eslint'],
  extends: ['next/core-web-vitals', 'eslint:recommended', 'plugin:@typescript-eslint/recommended'],
  rules: {
    // Keep your existing rules
    '@next/next/no-html-link-for-pages': 'off',

    // Prefer the TS-aware unused-vars rule and allow _-prefixed vars/args (fixes: _value, _id)
    'no-unused-vars': 'off',
    '@typescript-eslint/no-unused-vars': [
      'error',
      {
        args: 'all',
        argsIgnorePattern: '^_',
        varsIgnorePattern: '^_',
        caughtErrorsIgnorePattern: '^_',
        ignoreRestSiblings: true,
      },
    ],

    // Temporarily allow namespaces in TS until refactor (fixes: env.ts no-namespace)
    '@typescript-eslint/no-namespace': 'off',
  },
};