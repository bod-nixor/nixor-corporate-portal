module.exports = {
  root: true,
  extends: ["next/core-web-vitals", "eslint:recommended"],
  rules: {
    "@next/next/no-html-link-for-pages": "off",
    "no-unused-vars": ["error", { args: "none" }]
  }
};
