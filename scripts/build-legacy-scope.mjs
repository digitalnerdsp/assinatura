// Gera public/assets/css/legacy-scope.css: o CSS Bootstrap das telas internas, preso à classe ".legacy-scope".
// Assim as telas ainda em Bootstrap convivem com temas Tailwind sem um estragar o outro.
// Uso (precisa de: npm i postcss postcss-prefix-selector): node scripts/build-legacy-scope.mjs
import fs from 'node:fs';
import path from 'node:path';
import postcss from 'postcss';
import prefixer from 'postcss-prefix-selector';

const SCOPE = '.legacy-scope';
const dir = 'public/assets/css';
const files = ['bootstrap.min.css', 'bootstrap-extended.css', 'app.css', 'typography.css', 'dark-theme.css', 'contrast.css', 'drive.css'];

const css = files.map((f) => `/* ${f} */\n` + fs.readFileSync(path.join(dir, f), 'utf8')).join('\n');

const result = await postcss([
    prefixer({
        prefix: SCOPE,
        transform(prefix, selector, prefixed) {
            const s = selector.trim();
            if (s.startsWith(SCOPE)) return s;
            if (/^(html|body|:root)$/.test(s)) return prefix;
            if (/^(html|:root)\.dark-theme$/.test(s)) return `html.dark-theme ${prefix}`;
            if (/^html\.dark-theme\s/.test(s)) return s.replace(/^html\.dark-theme\s+/, `html.dark-theme ${prefix} `);
            if (/^(html|body)\s/.test(s)) return s.replace(/^(html|body)\s+/, `${prefix} `);
            return prefixed;
        },
    }),
]).process(css, { from: undefined });

fs.writeFileSync(path.join(dir, 'legacy-scope.css'), result.css);
console.log(`legacy-scope.css: ${(result.css.length / 1024).toFixed(0)} KB`);
