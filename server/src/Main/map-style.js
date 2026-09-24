/*
 * 地図の見た目。**Android アプリと同じ決め方をここに1本で持つ。**
 *
 * ## なぜ要るのか
 *
 * Website の地図は、ノードが全部「透明な当たり判定」で、見えているのは文字のピルだけだった。
 * 種類ごとの色も形も無く、ズームの閾値は全体で1つ(`ZOOM_THRESHOLD = 1`)。
 * アプリ側は種類ごとに色・形・閾値を持ち、表示調整のパネルまである。
 * **同じ校舎の同じ地図なのに、見た目が別物**だった。
 *
 * 利用者の指示で揃える(2026-09-03)。写す元は
 * `app/src/main/java/com/ito/kosenmap/GeneralMapNodeRenderer.kt`。
 *
 * ## 倍率の読み替え
 *
 * アプリの `scale` は倍率(1.0 = 等倍)、Leaflet は `zoom`(整数を含む対数)。
 * **`scale = 2 ^ zoom`** で読み替える。こうすると:
 *
 *   - アプリの medium 閾値 1.0 → Leaflet の zoom 0
 *   - アプリの high 閾値 2.0 → Leaflet の zoom 1
 *
 * 元の Website も「zoom 1 以上で部屋名」だったので、**閾値を揃えると
 * 従来の見え方がそのまま再現される。** 設定を触っていない人の地図は変わらない。
 */
(function () {
    'use strict';

    /**
     * 種類ごとの色。`generalNodeColor()` と同じ値。
     *
     * **建物名と部屋名は違う色**(2026-09-03)。外を引くと建物名、寄ると部屋名へ
     * 入れ替わるが、同じ色の同じ丸だと**入れ替わったことが分からなかった**。
     */
    const COLORS = {
        room: '#06B6D4',
        facility: '#0F766E',
        stairs: '#8B5CF6',
        road: '#F59E0B',
        wall: '#64748B',
        entrance: '#10B981',
        wifi_router: '#2563EB',
    };

    /**
     * 種類ごとの大きさの既定(%)。`defaultNodeTypeSizePercent()` と同じ値。
     *
     * 建物名は引いているときに1つだけ見えるので大きく、
     * 道の点と壁は数が多いので小さく —— 同じ大きさだと点の網が地図を覆う。
     */
    const DEFAULT_SIZES = {
        facility: 130,
        road: 70,
        wall: 70,
    };

    /** 種類ごとの日本語。詳細と設定に出す。 */
    const LABELS = {
        room: '部屋',
        facility: '施設',
        stairs: '階段',
        road: '通路の点',
        entrance: '出入口',
        wall: '壁',
        wifi_router: 'Wi-Fi ルーター',
    };

    /** 置ける形。`MapNodeShape` と同じ顔ぶれ。 */
    const SHAPES = ['circle', 'square', 'triangle', 'diamond', 'hexagon', 'star', 'pin'];

    const SHAPE_LABELS = {
        circle: '円', square: '四角', triangle: '三角',
        diamond: '菱形', hexagon: '六角', star: '星', pin: 'ピン',
    };

    /** 種類ごとの既定の形。アプリの初期値に合わせてある。 */
    const DEFAULT_SHAPES = {
        room: 'circle',
        facility: 'hexagon',
        stairs: 'triangle',
        road: 'circle',
        entrance: 'pin',
        wall: 'square',
        wifi_router: 'diamond',
    };

    /** アプリの medium / high 閾値(倍率)。 */
    const MEDIUM_SCALE = 1;
    const HIGH_SCALE = 2;

    /** Leaflet の zoom を、アプリの倍率へ読み替える。 */
    function scaleForZoom(zoom) {
        return Math.pow(2, zoom);
    }

    /**
     * 種類ごとの「表示を始める倍率」。`defaultNodeTypeZoomThreshold()` と同じ。
     *
     * - 出入口は縮小しても出す(建物の入口が分からないと案内が始められない)
     * - 建物名は縮小時から出し、拡大すると部屋名へ入れ替わる(下の hideScale)
     */
    function defaultShowScale(type) {
        if (type === 'entrance' || type === 'facility') return 0;
        if (type === 'room') return HIGH_SCALE;
        return MEDIUM_SCALE;
    }

    /**
     * 建物名が消える倍率。**これだけ「終わり」を持つ。**
     * 拡大すると建物名が消えて部屋名に入れ替わる、という見せ方を保つため。
     */
    function hideScale(type) {
        return type === 'facility' ? HIGH_SCALE : Infinity;
    }

    /* ------------------------------------------------ 表示調整(閲覧者ごと) */

    const STORAGE_KEY = 'km-map-tuning-v1';

    /**
     * 既定値。**触っていない人の見た目を変えないこと。**
     *
     * `roadGraph` と `accessPoints` は既定で消す —— 従来の Website も
     * 通過点は管理者にしか出していなかった。
     */
    const DEFAULTS = {
        nodeSize: 100,      // %
        labelSize: 100,     // %
        labelMode: 'icon_and_text', // icon_only / icon_and_text / text_only
        roomNames: true,
        accessPoints: false,
        roadGraph: false,
        /*
         * 壁の線(2026-09-05、利用者の要望)。**既定は出す** ——
         * これまで消す手段が無く常に出ていたので、既定を変えると
         * 設定を触っていない人の地図が勝手に変わる。
         */
        wallLines: true,
        distances: false,
        /*
         * 屋外で拡大したときの建物名(2026-09-25、利用者の要望)。
         * それまでは拡大すると**丸ごと消えていた** —— 屋外には入れ替わる部屋名が無いので、
         * 寄った途端に何の建物か分からなくなる。
         *   faded … 図形は消し、名前だけ薄く残す(既定。押せる地点の邪魔をしない)
         *   hidden … これまでどおり消す
         *   shown … 引いたときと同じに出す
         * アプリの `FacilityZoomMode`(GeneralMapNodeRenderer.kt)と同じ値。
         */
        facilityZoomed: 'faded',
        theme: 'system',    // system / light / dark
        types: {},          // { room: { shape, visible, label, showScale } }
    };

    /**
     * 読み出す。**壊れていたら既定に戻す。**
     *
     * localStorage は閲覧者の手元にあり、古い版の値や手で書き換えた値が入りうる。
     * そこで落ちると**地図が真っ白になる**ので、読めなければ黙って既定へ倒す。
     */
    function load() {
        const tuning = Object.assign({}, DEFAULTS, { types: {} });
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) return tuning;
            const stored = JSON.parse(raw);
            if (!stored || typeof stored !== 'object') return tuning;
            Object.keys(DEFAULTS).forEach(function (key) {
                if (key === 'types') return;
                if (typeof stored[key] === typeof DEFAULTS[key]) tuning[key] = stored[key];
            });
            if (stored.types && typeof stored.types === 'object') {
                Object.keys(COLORS).forEach(function (type) {
                    const entry = stored.types[type];
                    if (entry && typeof entry === 'object') tuning.types[type] = entry;
                });
            }
        } catch (e) {
            // 読めないだけ。**地図は出す**
        }
        return tuning;
    }

    function save(tuning) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(tuning));
        } catch (e) {
            // 保存できなくても今の表示は変えない(プライベートウィンドウなど)
        }
    }

    /* ------------------------------------------------ 図形 */

    /**
     * 図形を SVG のパスにする。中心 (0,0)、外接半径 r。
     * `drawNodeShape()` と同じ比率にしてある(面積感を揃えるための係数も同じ)。
     */
    function shapePath(shape, r) {
        function polygon(radius, sides, startDeg) {
            const points = [];
            for (let i = 0; i < sides; i++) {
                const a = (startDeg + i * 360 / sides) * Math.PI / 180;
                points.push((radius * Math.cos(a)).toFixed(2) + ',' + (radius * Math.sin(a)).toFixed(2));
            }
            return 'M' + points.join('L') + 'Z';
        }

        switch (shape) {
            case 'square': {
                const h = r * 0.85;
                return 'M' + (-h) + ',' + (-h) + 'h' + (h * 2) + 'v' + (h * 2) + 'h' + (-h * 2) + 'Z';
            }
            case 'triangle': return polygon(r * 1.15, 3, -90);
            case 'diamond': return polygon(r * 1.1, 4, -90);
            case 'hexagon': return polygon(r, 6, -90);
            case 'star': {
                const points = [];
                for (let i = 0; i < 10; i++) {
                    const radius = i % 2 === 0 ? r : r * 0.45;
                    const a = (-90 + i * 36) * Math.PI / 180;
                    points.push((radius * Math.cos(a)).toFixed(2) + ',' + (radius * Math.sin(a)).toFixed(2));
                }
                return 'M' + points.join('L') + 'Z';
            }
            case 'pin': {
                const head = r * 0.72;
                const cy = -r * 0.28;
                const foot = cy + head * 0.62;
                return 'M0,' + r
                    + 'L' + (-head * 0.78).toFixed(2) + ',' + foot.toFixed(2)
                    + 'L' + (head * 0.78).toFixed(2) + ',' + foot.toFixed(2) + 'Z'
                    + 'M0,' + cy.toFixed(2) + 'm' + (-head).toFixed(2) + ',0'
                    + 'a' + head.toFixed(2) + ',' + head.toFixed(2) + ' 0 1,0 ' + (head * 2).toFixed(2) + ',0'
                    + 'a' + head.toFixed(2) + ',' + head.toFixed(2) + ' 0 1,0 ' + (-head * 2).toFixed(2) + ',0';
            }
            case 'circle':
            default:
                return 'M0,' + (-r) + 'a' + r + ',' + r + ' 0 1,0 0,' + (r * 2)
                    + 'a' + r + ',' + r + ' 0 1,0 0,' + (-r * 2);
        }
    }

    window.KM_MAP_STYLE = {
        COLORS: COLORS,
        LABELS: LABELS,
        SHAPES: SHAPES,
        SHAPE_LABELS: SHAPE_LABELS,
        DEFAULT_SHAPES: DEFAULT_SHAPES,
        DEFAULT_SIZES: DEFAULT_SIZES,
        DEFAULTS: DEFAULTS,
        MEDIUM_SCALE: MEDIUM_SCALE,
        HIGH_SCALE: HIGH_SCALE,
        scaleForZoom: scaleForZoom,
        defaultShowScale: defaultShowScale,
        hideScale: hideScale,
        shapePath: shapePath,
        load: load,
        save: save,

        /** 種類の設定を、既定で埋めて返す。 */
        typeSetting: function (tuning, type) {
            const stored = (tuning.types && tuning.types[type]) || {};
            return {
                shape: SHAPES.indexOf(stored.shape) >= 0 ? stored.shape : (DEFAULT_SHAPES[type] || 'circle'),
                visible: stored.visible === undefined ? true : stored.visible !== false,
                label: stored.label === undefined ? null : stored.label !== false,
                size: typeof stored.size === 'number' ? stored.size : (DEFAULT_SIZES[type] || 100),
                showScale: typeof stored.showScale === 'number' ? stored.showScale : null,
            };
        },

        color: function (type) {
            return COLORS[type] || '#94A3B8';
        },

        /**
         * 「文字」のつまみを係数(1 = 等倍)にする。
         *
         * **壊れた値でも倒れないこと。** localStorage は閲覧者の手元にあり、
         * 古い版の値や手で書き換えた値が入りうる。つまみの範囲(50〜200%)へ
         * 丸めてから返す —— 0 を書かれると**地図の文字が全部消える**。
         */
        labelScale: function (tuning) {
            const raw = Number(tuning && tuning.labelSize);
            if (!isFinite(raw)) return 1;
            return Math.min(200, Math.max(50, raw)) / 100;
        },

        /**
         * その係数を CSS へ渡す。
         *
         * ラベルは Leaflet の tooltip で、**大きさは CSS が持っている** ——
         * マーカーを作り直しても字は同じ大きさのまま出る。だから
         * 地点(`nodeSize`)のように描き直しでは変えられず、変数側を動かす。
         *
         * **`style="…"` を書かない**(CSP に `'unsafe-inline'` が無い)。
         * `setProperty` は CSSOM の API で、属性を書くのとは別物なので掛からない
         * —— 種類の色を DOM のプロパティで設定しているのと同じ理由。
         */
        applyLabelScale: function (tuning) {
            const root = document.documentElement;
            if (!root) return;
            root.style.setProperty('--km-label-scale', String(this.labelScale(tuning)));
        },

        /**
         * その倍率でこの種類を出すか。
         *
         * **層の設定は倍率とは別軸**(アプリと同じ)。
         * 「部屋名を消す」を選んだら、どれだけ寄っても出さない。
         */
        isVisible: function (tuning, type, zoom, floor) {
            const setting = this.typeSetting(tuning, type);
            if (!setting.visible) return false;

            if (type === 'facility' || type === 'room') {
                if (!tuning.roomNames) return false;
            } else if (type === 'wifi_router') {
                if (!tuning.accessPoints) return false;
            } else if (type === 'road') {
                if (!tuning.roadGraph) return false;
            }

            const scale = scaleForZoom(zoom);
            const start = setting.showScale === null ? defaultShowScale(type) : setting.showScale;
            if (scale < start) return false;

            if (scale < hideScale(type)) return true;
            // 拡大して消える側に入った建物名。**屋外だけ**、設定に応じて残す(薄くするのは facilityFaded)
            return String(floor) === 'outside' && this.facilityZoomMode(tuning) !== 'hidden';
        },

        /** 屋外で拡大したときの建物名の扱い。**知らない値は既定(薄く)へ倒す。** */
        facilityZoomMode: function (tuning) {
            const value = tuning && tuning.facilityZoomed;
            return value === 'hidden' || value === 'shown' ? value : 'faded';
        },

        /**
         * いま建物名を**薄く**出す状態か。図形を消し、名前を薄く、押せなくする
         * (`styles.css` の `.km-facility-faded`)。屋外だけ。
         */
        facilityFaded: function (tuning, zoom, floor) {
            return String(floor) === 'outside'
                && this.facilityZoomMode(tuning) === 'faded'
                && scaleForZoom(zoom) >= hideScale('facility');
        },
    };
}());
