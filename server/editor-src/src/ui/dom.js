/*
 * 画面の部品を作る小さな道具(段 10)。文字は textContent で入れる(innerHTML は使わない)。
 * style 属性は書かない(CSP。幅などは CSSOM の setProperty)。
 */
export const $ = (id) => document.getElementById(id);

/**
 * el('button', {className: 'x', text: '押す', attrs: {type: 'button'}, on: {click: fn}}, [子…])
 */
export function el(tag, options = {}, children = []) {
  const node = document.createElement(tag);
  if (options.className) {
    node.className = options.className;
  }
  if (options.text !== undefined) {
    node.textContent = String(options.text);
  }
  for (const [name, value] of Object.entries(options.attrs ?? {})) {
    if (value === false || value === null || value === undefined) {
      continue;
    }
    node.setAttribute(name, value === true ? '' : String(value));
  }
  for (const [name, value] of Object.entries(options.dataset ?? {})) {
    node.dataset[name] = String(value);
  }
  for (const [name, fn] of Object.entries(options.on ?? {})) {
    node.addEventListener(name, fn);
  }
  for (const child of children) {
    if (child !== null && child !== undefined && child !== false) {
      node.append(child);
    }
  }
  return node;
}

/** <dialog> を開いて、閉じたときの returnValue を返す */
export function openDialog(dialog) {
  return new Promise((resolve) => {
    dialog.addEventListener('close', () => resolve(dialog.returnValue), { once: true });
    dialog.returnValue = '';
    dialog.showModal();
  });
}

export function anyDialogOpen() {
  return document.querySelector('dialog[open]') !== null;
}

export function formatBytes(n, lang) {
  if (!Number.isFinite(n)) {
    return '';
  }
  if (n < 1024) {
    return `${n} B`;
  }
  if (n < 1024 * 1024) {
    return `${(n / 1024).toFixed(1)} KB`;
  }
  const mb = (n / 1024 / 1024).toFixed(1);
  return lang === 'en' ? `${mb} MB` : `${mb} MB`;
}

export function downloadBlob(name, blob) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

export function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}
