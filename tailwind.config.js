/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./public/**/*.html",
    "./public/**/*.js",
    "./public/**/*.php",
    "./api/**/*.php",
  ],
  safelist: [
    // Common layout utilities used in JS templates
    { pattern: /(grid|flex|inline-flex|hidden|block|relative|absolute|fixed|sticky)/ },
    { pattern: /(gap|space)-(0|1|2|3|4|5|6|8|10|12)/ },
    { pattern: /(p|px|py|pt|pr|pb|pl|m|mx|my|mt|mr|mb|ml)-(0|1|2|3|4|5|6|8|10|12)/ },
    { pattern: /(w|h)-(0|1|2|3|4|5|6|8|10|12|16|20|24|32|40|48|64)/ },
    { pattern: /(rounded|rounded-md|rounded-lg|rounded-xl|rounded-2xl|rounded-full)/ },
    { pattern: /(text|bg|border)-(slate|red|green|amber|blue|indigo)-(50|100|200|300|400|500|600|700|800|900|950)/ },
    { pattern: /(text)-(xs|sm|base|lg|xl|2xl)/ },
    { pattern: /(font)-(medium|semibold|bold)/ },
    { pattern: /(items|justify)-(start|center|end|between)/ },
    { pattern: /(col-span)-(1|2|3|4|5|6|7|8|9|10|11|12)/ },
    { pattern: /(sm|md|lg|xl):(col-span)-(1|2|3|4|5|6|7|8|9|10|11|12)/ },
     "md:col-span-2",
    "xl:col-span-3",
    "col-span-1",
    "col-span-2",
    "col-span-3"
  ],
  theme: { extend: {} },
  plugins: [],
};