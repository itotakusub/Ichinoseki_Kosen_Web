/*!
 * KosenMap Admin — 地図編集
 *
 * 旧 Main/builder.js の後継。操作感(地図クリックで配置 / マーカー2つクリックで結合 / 整列)は
 * 引き継ぎつつ、次の点を変えている:
 *
 *  - **保存は操作ごと**。旧実装はグラフ全体を毎回 POST し、サーバーがそれをコードファイルへ
 *    書き戻していた。今のスキーマには occupant_name(教職員氏名)があり、ブラウザ側の
 *    ノードはその列を持たないため、丸ごと保存すると氏名が全件消える
 *  - **移動・編集・削除を追加**。旧実装は追加と整列しかできなかった
 *  - **undo/redo は持たない**。旧実装のものは in-memory でリロードすると消え、整列は対象外で、
 *    しかも「戻す」たびに全体を再保存していた。サーバーへ即時反映する設計でクライアント側だけの
 *    履歴を持つと実際の状態と食い違うため、削除の確認と監査ログ(タイムライン)で追う形にした
 */
(() => {
  'use strict';

  /*
   * KmI18n.t() は辞書に無いキーを**キーのまま**返す(i18n.js)。そのままだと
   * 辞書に足す前の文言が「page.mapEditor.…」と画面に出るので、そのときは既定の文へ落とす。
   */
  const t = (key, fallback) => {
    const value = window.KmI18n ? window.KmI18n.t(key) : null;
    return value && value !== key ? value : fallback;
  };

  const statusEl = document.getElementById('km-editor-status');
  const hintEl = document.getElementById('km-editor-hint');
  const wrap = document.getElementById('km-map-editor-wrap');
  const alignControls = document.getElementById('km-align-controls');
  const nodeCountEl = document.getElementById('km-node-count');

  let mode = 'none';
  let selectedForEdge = [];
  let selectedForAlign = [];
  let nodeModal = null;

  const setStatus = (message, isError = false) => {
    if (!statusEl) return;
    statusEl.textContent = message;
    statusEl.classList.toggle('text-danger', isError);
    statusEl.classList.toggle('text-body-secondary', !isError);
  };

  /**
   * タイムアウト付きの fetch(既定 15 秒)。時間切れは AbortError で reject する。
   * **本文の読み取り(read)まで時間に含める**(ヘッダーだけ返って本文が止まる場合も打ち切る)。
   */
  const FETCH_TIMEOUT_MS = 15000;
  const fetchWithTimeout = async (url, options = {}, read = (response) => response, timeoutMs = FETCH_TIMEOUT_MS) => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetch(url, { ...options, signal: controller.signal });
      return await read(response);
    } finally {
      clearTimeout(timer);
    }
  };

  /** サーバーへ1操作送る。CSRF トークンは head.php の meta から取る。 */
  const send = async (payload) => {
    let res;
    let body;
    try {
      [res, body] = await fetchWithTimeout('./api/map-edit.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-KM-CSRF': document.querySelector('meta[name="km-csrf"]')?.content ?? '',
        },
        body: JSON.stringify(payload),
      }, async (response) => {
        /*
         * JSON でない本文(nginx の 429 など)は {} にする。ただし時間切れ(AbortError)は
         * {} に潰さず外へ投げる。潰すと「保存に失敗しました」と言い切ってしまうため。
         */
        const parsed = await response.json().catch((error) => {
          if (error?.name === 'AbortError') {
            throw error;
          }
          return {};
        });
        return [response, parsed];
      });
    } catch (error) {
      /*
       * **時間切れは「失敗」と言い切らない。** 送った操作はサーバーで反映済みかもしれない
       * (応答だけが遅れた)。そのまま同じ操作を繰り返すと二重に入るので、読み直しを促す。
       */
      if (error?.name === 'AbortError') {
        throw new Error(t('page.mapEditor.timeout', 'サーバーから応答がありません。反映されたか分からないため、ページを再読み込みして確かめてください。'));
      }
      throw error;
    }
    if (res.status === 429) {
      throw new Error(t('page.mapEditor.busy', '操作が多すぎます。少し待ってから試してください。'));
    }
    if (!res.ok || !body.success) {
      throw new Error(body.error || t('page.mapEditor.saveError', '保存に失敗しました'));
    }
    return body;
  };

  /** 変更後にローカルの graphData を描き直す。app.js が公開している関数を使う。 */
  const redraw = () => {
    window.renderGlobalLabels?.();
    window.renderGlobalEdges?.();
    window.renderEventOverlay?.();
    if (nodeCountEl && window.graphData) {
      nodeCountEl.textContent = String(Object.keys(window.graphData.nodes).length);
    }
  };

  /*
   * ---- イベント編集モード ----
   *
   * window.KM_EDIT_EVENT が入っているときだけ使える(map-editor.php が ?event=<id> で立てる)。
   * ここで触るのは**重ね合わせだけ**で、恒久データ(場所や経路)は変わらない。
   */
  const EVENT = window.KM_EDIT_EVENT || null;
  const eventId = EVENT ? EVENT.id : null;

  const POI_CATEGORIES = [
    ['food', '模擬店'], ['exhibit', '展示'], ['reception', '受付'],
    ['firstaid', '救護'], ['toilet', 'トイレ'], ['stage', 'ステージ'], ['other', 'その他'],
  ];

  /** 通行止めを送って、手元の graphData にも同じ状態を反映する。 */
  const toggleEdgeClosure = async (from, to) => {
    const reason = window.prompt(
      t('page.mapEditor.promptClosureReason', '通行止めの理由(空欄可。解除するときは無視されます)'),
      '',
    );
    // prompt のキャンセルは中止。空文字は「理由なしで閉じる」
    if (reason === null) return;

    const body = await send({
      action: 'event.closure.toggle', eventId, target: 'edge', from, to, reason,
    });

    // **向きを問わず同じ1本**として扱う(サーバー側と同じ考え方)
    window.graphData.edges.forEach((edge) => {
      const match = (edge.source === from && edge.target === to)
        || (edge.source === to && edge.target === from);
      if (!match) return;
      edge.closed = body.closed;
      edge.closureReason = body.closed ? reason : '';
    });
    redraw();
    setStatus(body.closed
      ? t('page.mapEditor.savedClosure', '通行止めにしました')
      : t('page.mapEditor.savedClosureOff', '通行止めを解除しました'));
  };

  const toggleNodeClosure = async (id) => {
    const reason = window.prompt(
      t('page.mapEditor.promptClosureReason', '通行止めの理由(空欄可。解除するときは無視されます)'),
      '',
    );
    if (reason === null) return;

    const body = await send({
      action: 'event.closure.toggle', eventId, target: 'node', node: id, reason,
    });
    const node = window.graphData.nodes[id];
    node.closed = body.closed;
    node.closureReason = body.closed ? reason : '';
    redraw();
    setStatus(body.closed
      ? t('page.mapEditor.savedClosure', '立入禁止にしました')
      : t('page.mapEditor.savedClosureOff', '立入禁止を解除しました'));
  };

  const setNodeAlias = async (id) => {
    const node = window.graphData.nodes[id];
    const alias = window.prompt(
      `${t('page.mapEditor.promptAlias', '臨時の名前(空欄で解除)')}\n\n${node.name}`,
      node.alias || '',
    );
    if (alias === null) return;

    await send({ action: 'event.alias.set', eventId, node: id, alias });
    if (alias.trim() === '') {
      delete node.alias;
    } else {
      node.alias = alias.trim();
    }
    redraw();
    setStatus(alias.trim() === ''
      ? t('page.mapEditor.savedAliasOff', '臨時の名前を解除しました')
      : t('page.mapEditor.savedAlias', '臨時の名前を設定しました'));
  };

  const createPoi = async (x, y) => {
    const name = window.prompt(t('page.mapEditor.promptPoiName', '臨時の地点の名前(例: たこ焼き)'), '');
    if (name === null || name.trim() === '') return;

    const list = POI_CATEGORIES.map(([, label], i) => `${i + 1}. ${label}`).join('\n');
    const choice = window.prompt(`${t('page.mapEditor.promptPoiCategory', '種別を番号で選んでください')}\n${list}`, '1');
    if (choice === null) return;
    const category = (POI_CATEGORIES[Number(choice) - 1] || POI_CATEGORIES[POI_CATEGORIES.length - 1])[0];

    /*
     * **案内先(アンカー)を付けるかどうか。**
     * 付けると検索候補に出て経路案内までできる。付けないと地図に出るだけ。
     * 中庭の模擬店のように経路グラフ上に点が無い場所もあるので、任意にしてある。
     */
    const anchorNodeId = window.prompt(
      t('page.mapEditor.promptPoiAnchor', '案内先にする地点のID(空欄なら表示のみ)'),
      '',
    );
    if (anchorNodeId === null) return;
    if (anchorNodeId.trim() !== '' && !window.graphData.nodes[anchorNodeId.trim()]) {
      setStatus(t('page.mapEditor.unknownAnchor', 'その地点IDは見つかりません'), true);
      return;
    }

    const body = await send({
      action: 'event.poi.create',
      eventId,
      floor: String(window.currentFloor),
      name,
      category,
      x,
      y,
      anchorNodeId: anchorNodeId.trim(),
      note: '',
    });

    window.graphData.eventPois = window.graphData.eventPois || [];
    window.graphData.eventPois.push({
      id: body.id, eventId, floor: String(window.currentFloor),
      name, category, x, y,
      anchorNodeId: anchorNodeId.trim() || null, note: null,
    });
    redraw();
    setStatus(t('page.mapEditor.savedPoi', '臨時の地点を追加しました'));
  };

  const HINTS = {
    none: ['page.mapEditor.hintNone', 'モードを選んでください。'],
    node: ['page.mapEditor.hintNode', '地図をクリックすると、その場所に地点を追加します。'],
    point: ['page.mapEditor.hintPoint', '地図をクリックすると通過点を追加します(経路用の目印)。'],
    move: ['page.mapEditor.hintMove', '動かしたい地点をクリックし、次に移動先をクリックします。'],
    edit: ['page.mapEditor.hintEdit', '編集したい地点をクリックします。'],
    edge: ['page.mapEditor.hintEdge', 'つなぎたい地点を2つ続けてクリックします。'],
    align: ['page.mapEditor.hintAlign', '揃えたい地点をクリックで選び、下のボタンを押します。'],
    delete: ['page.mapEditor.hintDelete', '削除したい地点をクリックします(つながる経路も消えます)。'],
    'event-closure': [
      'page.mapEditor.hintEventClosure',
      '経路を閉じるには両端の地点を2つ続けてクリック。地点そのものを閉じるには同じ地点を2回クリックします。',
    ],
    'event-poi': ['page.mapEditor.hintEventPoi', '地図をクリックすると、そこに臨時の地点を置きます。'],
    'event-alias': ['page.mapEditor.hintEventAlias', '臨時の名前を付けたい地点をクリックします。'],
  };

  const setMode = (next) => {
    mode = next;
    selectedForEdge = [];
    selectedForAlign = [];
    window.builderMode = next === 'none' ? 'none' : next;

    document.querySelectorAll('.km-mode').forEach((b) => {
      b.classList.toggle('active', b.dataset.kmMode === next);
    });
    alignControls?.classList.toggle('d-none', next !== 'align');
    wrap?.classList.toggle('km-editing', next !== 'none');

    const [key, fallback] = HINTS[next] ?? HINTS.none;
    if (hintEl) {
      hintEl.dataset.i18n = key;
      hintEl.textContent = t(key, fallback);
    }
    setStatus('');
  };

  document.querySelectorAll('.km-mode').forEach((button) => {
    button.addEventListener('click', () => setMode(button.dataset.kmMode));
  });

  // 「移動」は 2 クリック(対象 → 移動先)。1つ目に選んだノードをここに覚える。
  let movingNodeId = null;

  /**
   * app.js の marker.on('click') は builderMode が "edge" / "align" のときだけ
   * window.handleMarkerClickForBuilder を呼ぶ。move / edit / delete でも
   * マーカーを掴みたいので、その両方の名前でこちらへ流し込む。
   */
  window.handleMarkerClickForBuilder = async (id) => {
    const node = window.graphData?.nodes?.[id];
    if (!node) return;

    try {
      if (mode === 'event-alias') {
        await setNodeAlias(id);
        return;
      }

      if (mode === 'event-closure') {
        /*
         * 2つ続けてクリックで経路、**同じ地点を2回**で地点そのもの。
         * 別々のモードに割るより、この方が操作が少なくて済む。
         */
        if (selectedForEdge.length === 1 && selectedForEdge[0] === id) {
          selectedForEdge = [];
          await toggleNodeClosure(id);
          return;
        }
        if (selectedForEdge.includes(id)) return;
        selectedForEdge.push(id);
        setStatus(`${selectedForEdge.length}/2 ${t('page.mapEditor.selected', '選択中')}`);
        if (selectedForEdge.length === 2) {
          const [from, to] = selectedForEdge;
          selectedForEdge = [];
          await toggleEdgeClosure(from, to);
        }
        return;
      }

      if (mode === 'edge') {
        if (selectedForEdge.includes(id)) return;
        selectedForEdge.push(id);
        setStatus(`${selectedForEdge.length}/2 ${t('page.mapEditor.selected', '選択中')}`);
        if (selectedForEdge.length === 2) {
          const [from, to] = selectedForEdge;
          const body = await send({ action: 'edge.create', from, to });
          window.graphData.edges.push({ source: from, target: to, distance: body.distance });
          selectedForEdge = [];
          redraw();
          setStatus(t('page.mapEditor.savedEdge', '経路を追加しました'));
        }
        return;
      }

      if (mode === 'align') {
        if (!selectedForAlign.includes(id)) selectedForAlign.push(id);
        setStatus(`${selectedForAlign.length} ${t('page.mapEditor.selected', '選択中')}`);
        return;
      }

      if (mode === 'move') {
        movingNodeId = id;
        setStatus(t('page.mapEditor.pickDestination', '移動先を地図上でクリックしてください'));
        return;
      }

      if (mode === 'edit') {
        document.getElementById('km-node-id').value = id;
        // 教職員の地点に割り当てるときに写す。uuid が無い環境(移行前)は id
        document.getElementById('km-node-uid').value = node.uuid || id;
        /*
         * **title と subtitle を別に出す。** 繋いだ `name` から分かれ目は戻せない
         * (部屋名に空白が入っていると、どこで切れるか決められない)。
         * 列がまだ無い環境では `title` が来ないので、そのときは `name` を入れる。
         */
        document.getElementById('km-node-title').value = node.title ?? node.name ?? '';
        document.getElementById('km-node-subtitle').value = node.subtitle ?? '';
        document.getElementById('km-node-type').value = node.type ?? 'room';
        document.getElementById('km-node-occupant').value = node.occupantName ?? '';
        nodeModal ??= new bootstrap.Modal(document.getElementById('km-node-modal'));
        nodeModal.show();
        return;
      }

      if (mode === 'delete') {
        const label = node.name || id;
        if (!window.confirm(t('page.mapEditor.confirmDelete', 'この地点を削除しますか?') + `\n\n${label}`)) {
          return;
        }
        const body = await send({ action: 'node.delete', id });
        delete window.graphData.nodes[id];
        window.graphData.edges = window.graphData.edges.filter((e) => e.source !== id && e.target !== id);
        redraw();
        // 経路が1本も無かったときに「0本の経路も削除」と出るのは読みにくいので、
        // 巻き添えがあったときだけ本数を添える。
        const deleted = t('page.mapEditor.savedDelete', '削除しました。');
        setStatus(
          body.removedEdges > 0
            ? `${deleted} (${t('page.mapEditor.edgesRemoved', 'つながっていた経路も削除')}: ${body.removedEdges})`
            : deleted,
        );
      }
    } catch (error) {
      setStatus(error.message, true);
    }
  };

  /** 地図のクリック。追加系と、移動の2クリック目。 */
  const onMapClick = async (event) => {
    const noMapClick = ['none', 'edit', 'delete', 'align', 'edge', 'event-closure', 'event-alias'];
    if (noMapClick.includes(mode)) {
      return;
    }

    /*
     * Leaflet の CRS.Simple は lng=X / lat=Y。
     * **丸めない。** 列は decimal(10,2) にしてある(2026-09-03)——
     * Website が地図の正本になったので、丸めは往復のたびに積もる位置ずれになる。
     */
    const x = Math.round(event.latlng.lng * 100) / 100;
    const y = Math.round(event.latlng.lat * 100) / 100;

    try {
      if (mode === 'event-poi') {
        await createPoi(x, y);
        return;
      }

      if (mode === 'move') {
        if (!movingNodeId) {
          setStatus(t('page.mapEditor.pickNodeFirst', '先に動かす地点をクリックしてください'), true);
          return;
        }
        const body = await send({ action: 'node.move', id: movingNodeId, x, y });
        window.graphData.nodes[movingNodeId] = body.node;
        movingNodeId = null;
        redraw();
        setStatus(t('page.mapEditor.savedMove', '移動しました'));
        return;
      }

      /*
       * 地点の追加。**種類の語彙はアプリと同じ**になった(2026-09-03)——
       * 通過点は `point` ではなく `road`。
       */
      let title = '通過点';
      let type = 'road';
      if (mode === 'node') {
        title = window.prompt(t('page.mapEditor.promptName', '地点の名前を入力してください'), '');
        if (title === null || title.trim() === '') return;
        type = 'room';
      }

      const body = await send({
        action: 'node.create',
        floor: String(window.currentFloor),
        title,
        // 部屋番号は後から編集で入れる(置く操作を軽くしておく)
        subtitle: '',
        type,
        x,
        y,
      });
      window.graphData.nodes[body.node.id] = body.node;
      redraw();
      setStatus(t('page.mapEditor.savedNode', '追加しました'));
    } catch (error) {
      setStatus(error.message, true);
    }
  };

  // 編集の保存(モーダル)
  document.getElementById('km-node-save')?.addEventListener('click', async () => {
    const id = document.getElementById('km-node-id').value;
    try {
      const body = await send({
        action: 'node.update',
        id,
        title: document.getElementById('km-node-title').value,
        subtitle: document.getElementById('km-node-subtitle').value,
        type: document.getElementById('km-node-type').value,
        /*
         * **氏名はここでは送らない。**
         *
         * かつてここが氏名も一緒に送っており、画面が読み込んだ null を
         * そのまま送り返して氏名を消していた(氏名を伏せた状態で地点名を
         * 直しただけで失われた)。Website が正本へ戻ったいまも、
         * 氏名は下の専用の呼び出しでだけ書く。
         */
      });
      window.graphData.nodes[id] = body.node;

      /*
       * 氏名は**別の操作**として送る。値が変わったときだけ。
       * 伏せている(読めない)状態では触らない —— 読めない値を送り返さない。
       */
      const occupantField = document.getElementById('km-node-occupant');
      if (occupantField && !occupantField.readOnly) {
        const before = body.node.occupantName ?? '';
        if (occupantField.value !== before) {
          const updated = await send({
            action: 'node.occupant',
            id,
            occupantName: occupantField.value,
          });
          window.graphData.nodes[id] = updated.node;
        }
      }

      nodeModal?.hide();
      redraw();
      setStatus(t('page.mapEditor.savedEdit', '保存しました'));
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  // 整列の実行
  document.getElementById('km-align-exec')?.addEventListener('click', async () => {
    if (selectedForAlign.length < 2) {
      setStatus(t('page.mapEditor.alignNeedTwo', '2つ以上選んでください'), true);
      return;
    }
    const axis = window.confirm(t('page.mapEditor.alignAxis', 'X座標を揃えますか? (キャンセルで Y)')) ? 'x' : 'y';
    const first = window.graphData.nodes[selectedForAlign[0]];
    const input = window.prompt(t('page.mapEditor.alignValue', '揃える値'), String(first?.[axis] ?? 0));
    if (input === null) return;

    try {
      await send({ action: 'nodes.align', ids: selectedForAlign, axis, value: Number(input) });
      selectedForAlign.forEach((id) => {
        if (window.graphData.nodes[id]) window.graphData.nodes[id][axis] = Number(input);
      });
      selectedForAlign = [];
      redraw();
      setStatus(t('page.mapEditor.savedAlign', '整列しました'));
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  /*
   * フロア切り替えそのものは app.js に任せる。app.js は DOMContentLoaded 時に
   * document.querySelectorAll('.floor-btn') でハンドラを張り、changeFloor() の中で
   * window.currentFloor の更新・.active の付け替え・フロア画像の読み込みまで面倒を見る。
   * こちらのボタンにも .floor-btn と data-floor を付けてあるので、そのまま拾われる。
   *
   * ここでやるのは「階をまたいだ選択状態を持ち越さない」ことだけ。
   */
  document.getElementById('km-floor-buttons')?.addEventListener('click', (event) => {
    if (!event.target.closest('[data-floor]')) return;
    selectedForEdge = [];
    selectedForAlign = [];
    movingNodeId = null;
    setStatus('');
  });

  // app.js は DOMContentLoaded で地図を組み立てるので、window.map が出てから繋ぐ。
  /*
   * **見張るのは 60 秒まで。** 地図データの取得に失敗すると window.graphData は
   * 永遠に入らず、以前は 100ms ごとの見張りがタブを閉じるまで回り続けていた。
   * ただし回線が遅いと 60 秒を過ぎてから地図が出ることもある(app.js の 15 秒の打ち切りは
   * 応答ヘッダーまでで、本文の受信は含まない)。そのため見張りを止めた後も、app.js が
   * 地図を組み終えたときに出す km:map-ready で繋ぐ。**二重に繋がない**よう attached で守る。
   */
  const ATTACH_WAIT_LIMIT_MS = 60000;
  const attachStartedAt = Date.now();
  let attached = false;
  const attach = () => {
    if (attached) {
      return;
    }
    if (!window.map || !window.graphData) {
      if (Date.now() - attachStartedAt >= ATTACH_WAIT_LIMIT_MS) {
        setStatus(t('page.mapEditor.loadError', '地図データを読み込めませんでした。ページを再読み込みしてください。'), true);
        return;
      }
      setTimeout(attach, 100);
      return;
    }
    attached = true;
    window.map.on('click', onMapClick);
    setMode('none');
    if (nodeCountEl) {
      nodeCountEl.textContent = String(Object.keys(window.graphData.nodes).length);
    }
  };
  window.addEventListener('km:map-ready', attach, { once: true });
  attach();
})();
