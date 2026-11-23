/*
 * VS Code 型のエディタ(/code/)の「このエディタについて」(段 11-6。2026-11-09)。ライセンスの表示。
 *
 * 同梱する Open VSX の拡張機能は extensions/extensions.lock.json と同じ版(check.php が突き合わせる)。
 * 権利者は 11-09 に vsix の LICENSE で確かめた(Prettier の拡張機能: Esben Petersen・Prettier 本体: James Long and contributors・
 * Error Lens: Alexander)。npm の部品(monaco-vscode-api・VS Code の OSS・日本語パック)は LICENSE を同梱していないので、
 * package.json の license(MIT)と作者の名前だけを書く(年は確かめられないので書かない)。
 * VS Code の版は monaco-vscode-api の vscode/src/vs/platform/product/common/product.js の version(部品を上げたら直す。check.php が見る)
 */

export const ABOUT_COMPONENTS = [
  { name: 'Visual Studio Code(Code - OSS)', version: '1.145.2(monaco-vscode-api 39.0.3 に含まれるもの)', license: 'MIT', holder: 'Microsoft Corporation', url: 'https://github.com/microsoft/vscode' },
  { name: '@codingame/monaco-vscode-api', version: '39.0.3', license: 'MIT', holder: 'CodinGame', url: 'https://github.com/CodinGame/monaco-vscode-api' },
  { name: '日本語の言語パック(vscode-loc)', version: '39.0.3', license: 'MIT', holder: 'Microsoft Corporation', url: 'https://github.com/microsoft/vscode-loc' },
  { name: 'Prettier - Code formatter(拡張機能)', version: '12.4.0', license: 'MIT', holder: 'Esben Petersen', url: 'https://open-vsx.org/extension/esbenp/prettier-vscode' },
  { name: 'Prettier', version: '拡張機能 12.4.0 に含まれるもの', license: 'MIT', holder: 'James Long and contributors', url: 'https://prettier.io/' },
  { name: 'Error Lens(拡張機能)', version: '3.30.0', license: 'MIT', holder: 'Alexander(usernamehw)', url: 'https://open-vsx.org/extension/usernamehw/errorlens' },
  // 段 11-8(2026-11-09)。権利者は vsix の LICENSE.txt と node_modules の LICENSE で確かめた
  { name: 'Path Intellisense(拡張機能)', version: '2.8.0', license: 'MIT', holder: 'Christian Kohler', url: 'https://open-vsx.org/extension/christian-kohler/path-intellisense' },
  { name: 'ESLint(検査の部品 Linter)', version: '10.14.0', license: 'MIT', holder: 'OpenJS Foundation and other contributors', url: 'https://eslint.org/' },
  { name: '@eslint/js(推奨の規則)', version: '10.0.1', license: 'MIT', holder: 'OpenJS Foundation and other contributors', url: 'https://github.com/eslint/eslint/tree/main/packages/js' },
  { name: 'globals(ブラウザの大域変数の一覧)', version: '17.14.0', license: 'MIT', holder: 'Sindre Sorhus', url: 'https://github.com/sindresorhus/globals' },
  { name: 'HTMLHint', version: '1.9.2', license: 'MIT', holder: 'Yanis Wang・David Dias', url: 'https://htmlhint.com/' },
];

const MIT = 'Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), '
  + 'to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, '
  + 'and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:\n\n'
  + 'The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.\n\n'
  + 'THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, '
  + 'FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER '
  + 'LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.';

/** @param {'ja'|'en'} lang @returns {string} Markdown */
export function aboutMarkdown(lang) {
  const ja = lang !== 'en';
  const rows = ABOUT_COMPONENTS.map((c) => `| [${c.name}](${c.url}) | ${c.version} | ${c.license} | ${c.holder} |`);
  return [
    `# ${ja ? 'このエディタについて' : 'About this editor'}`,
    '',
    ja
      ? 'Web Code Editor (Workbench) は、高専マップ案内(ito4.jp)の運営者が、Visual Studio Code のオープンソース部分(Code - OSS)を'
        + 'ブラウザで動かす部品(monaco-vscode-api)で組み立てたものです。**Microsoft の製品ではありません。**'
      : 'Web Code Editor (Workbench) is built by the operator of ito4.jp from the open-source parts of Visual Studio Code (Code - OSS) '
        + 'using monaco-vscode-api. **It is not a Microsoft product.**',
    '',
    ja
      ? '- プロジェクトはこの端末のブラウザに保存します。サインインしていれば、サーバーにも保存します(利用規約 第8条の3)。\n'
        + '- 拡張機能は、運営者が選んで同梱したものだけが、このブラウザの中で動きます。拡張機能を追加することはできません。'
      : '- Projects are saved in this browser. When you are signed in, they are also saved on the server (Terms, Article 8-3).\n'
        + '- Only the extensions bundled by the operator run, inside this browser. You cannot add extensions.',
    '',
    `## ${ja ? '使っている部品とライセンス' : 'Components and licenses'}`,
    '',
    ja ? '| 部品 | 版 | ライセンス | 権利者 |' : '| Component | Version | License | Copyright holder |',
    '|---|---|---|---|',
    ...rows,
    '',
    ja
      ? 'VS Code に付属する言語・テーマなどの拡張機能も MIT ライセンス(Microsoft Corporation)です。各拡張機能の README・LICENSE は「拡張機能」の欄から見られます。'
      : 'The language and theme extensions that come with VS Code are also MIT-licensed (Microsoft Corporation). '
        + 'See each extension\'s README and LICENSE in the Extensions view.',
    '',
    '## MIT License',
    '',
    ja ? '上の表の部品は、どれも次の条件(MIT ライセンス)で使っています。著作権の表示は表の「権利者」のとおりです。' : 'All components above are used under the following terms. Copyright notices are as listed above.',
    '',
    '```text',
    MIT,
    '```',
    '',
  ].join('\n');
}
