const fs = require('fs');
const path = require('path');

const dir = 'c:/Users/Shahzain/Documents/NCP/nixor-corporate-portal/public';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.html') && f !== 'login.html');

const scriptHtml = `
  <!-- Theme Init Script to prevent FOUC -->
  <script>
    (function() {
      try {
        const theme = localStorage.getItem('nixor_theme') || 'theme-default';
        if (theme !== 'theme-default') {
          document.documentElement.classList.add(theme);
        }
      } catch (e) {}
    })();
  </script>`;

files.forEach(file => {
    const filePath = path.join(dir, file);
    let content = fs.readFileSync(filePath, 'utf8');
    if (!content.includes('nixor_theme') && content.includes('<head>')) {
        content = content.replace(/<head>/i, '<head>' + scriptHtml);
        fs.writeFileSync(filePath, content);
        console.log('Injected into ' + file);
    }
});
