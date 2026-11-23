/*
 * VS Code 型のエディタの入口(段 11。2026-11-08)。
 *
 * 日本語パックは「VS Code のどの部品よりも先に」読む決まり(読んだ時点で日本語に切り替わる)。
 * そのため、言語を決めてからパックを読み、そのあとでワークベンチを読む(どちらも動的 import)。
 * 言語は ?lang=en|ja → 前回の選択(localStorage)→ ブラウザの言語の順。既定は日本語
 */
const LANG_KEY = 'km-code-lang';

function pickLang() {
  const q = new URLSearchParams(location.search).get('lang');
  if (q === 'en' || q === 'ja') {
    try {
      localStorage.setItem(LANG_KEY, q);
    } catch {
      // 保存できなくても、その回は選んだ言語で開く
    }
    return q;
  }
  try {
    const saved = localStorage.getItem(LANG_KEY);
    if (saved === 'en' || saved === 'ja') {
      return saved;
    }
  } catch {
    // 読めなければ既定へ
  }
  return (navigator.language || 'ja').toLowerCase().startsWith('en') ? 'en' : 'ja';
}

const lang = pickLang();
document.documentElement.lang = lang;
globalThis.__kmCodeLang = lang;
globalThis.__kmCodeT0 = performance.now();

/**
 * 狭い画面(スマホ)では、軽い /editor/ を勧める(VS Code の画面は PC 向け・読み込みも重い)。
 * 「このまま開く」を選んだら、そのタブの間は聞かない
 */
function askOnNarrowScreen() {
  const NARROW_OK = 'km-code-narrow-ok';
  let accepted = false;
  try {
    accepted = sessionStorage.getItem(NARROW_OK) === '1';
  } catch {
    accepted = false;
  }
  if (accepted || !window.matchMedia('(max-width: 759px)').matches) {
    return Promise.resolve();
  }
  const ja = lang === 'ja';
  const box = document.createElement('main');
  box.className = 'km-code-narrow';
  const h = document.createElement('h1');
  h.textContent = ja ? 'この画面は PC 向けです' : 'This editor is made for PCs';
  const p = document.createElement('p');
  p.textContent = ja
    ? 'VS Code 型のエディタは画面が広く、読み込みも重めです。スマホでは「Web Code Editor」が使いやすく、同じプロジェクトを開けます。'
    : 'The workbench needs a wide screen and a larger download. On phones, the Web Code Editor is easier to use and opens the same projects.';
  const go = document.createElement('a');
  go.href = '/editor/';
  go.className = 'km-code-narrow-primary';
  go.textContent = ja ? 'Web Code Editor を開く' : 'Open the Web Code Editor';
  const stay = document.createElement('button');
  stay.type = 'button';
  stay.textContent = ja ? 'このまま開く' : 'Open anyway';
  box.append(h, p, go, stay);
  const root = document.getElementById('km-code');
  root.append(box);
  return new Promise((resolve) => {
    stay.addEventListener('click', () => {
      try {
        sessionStorage.setItem(NARROW_OK, '1');
      } catch {
        // 覚えられなくても、この回は開く
      }
      box.remove();
      resolve();
    });
  });
}

await askOnNarrowScreen();
if (lang === 'ja') {
  await import('@codingame/monaco-vscode-language-pack-ja');
}
await import('./workbench.js');
