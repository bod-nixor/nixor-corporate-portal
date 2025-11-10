// .eslintrc.cjs
module.exports = {
  root: true,
  parser: "@typescript-eslint/parser",
  plugins: ["@typescript-eslint"],
  extends: [
    "next/core-web-vitals",
    "eslint:recommended",
    "plugin:@typescript-eslint/recommended"
  ],
  env: {
    es2021: true,
    node: true,
    browser: true
  },
  rules: {
    "@next/next/no-html-link-for-pages": "off",
    "no-unused-vars": ["error", { args: "none" }],
    "@typescript-eslint/no-var-requires": "off"
  }
};