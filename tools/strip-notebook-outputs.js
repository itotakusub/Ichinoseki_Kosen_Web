/*
 * Notebook(.ipynb)の実行結果を消す。**本文とコードは残す。**
 *
 *   node tools/strip-notebook-outputs.js server/docs/01-daily-check.ipynb …
 *
 * 本番で走らせた結果(送信記録・メールアドレス・ホストの状態)を控えに載せないため。
 * あわせて、本文に書かれた**個人のメールアドレス**(Gmail など)を `you@example.com` に置き換える
 * —— 手順書では「自分のアドレスに書き換えてから流す」と書いてある箇所で、見本の値で足りる。
 * 書き方は Jupyter と同じ(字下げ 1・日本語はそのまま)にして、差分を小さく保つ。
 */
'use strict';

const fs = require('fs');

const PERSONAL = /[A-Za-z0-9._%+-]+@(?:gmail|yahoo|outlook|hotmail|icloud)\.(?:com|co\.jp|jp)/g;

let changed = 0;
let replaced = 0;
for (const file of process.argv.slice(2)) {
    const notebook = JSON.parse(fs.readFileSync(file, 'utf8'));
    for (const cell of notebook.cells || []) {
        if (cell.cell_type === 'code') {
            cell.outputs = [];
            cell.execution_count = null;
        }
        if (Array.isArray(cell.source)) {
            cell.source = cell.source.map((line) => line.replace(PERSONAL, () => { replaced += 1; return 'you@example.com'; }));
        } else if (typeof cell.source === 'string') {
            cell.source = cell.source.replace(PERSONAL, () => { replaced += 1; return 'you@example.com'; });
        }
    }
    fs.writeFileSync(file, JSON.stringify(notebook, null, 1) + '\n', 'utf8');
    changed += 1;
}
console.log(`Notebook の実行結果を消しました: ${changed} 冊(個人のアドレスの置き換え ${replaced} か所)`);
