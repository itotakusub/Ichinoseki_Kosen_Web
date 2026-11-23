/*
 * ストリートビュー(360°)の写真を見る(2026-11-07、段 6。利用者の答え「見せ方は B: WebGL」「撮りそろうまで管理者だけ」)。
 *
 * - 写真は正距円筒(横:縦 = 2:1)。地点の詳細の「360°」ボタン(Main/app.js)と、地図編集の地点の窓(admin/assets/js/map-editor.js)から開く
 * - **押すまで写真を取らない**(1 枚 1〜2MB)。取り先は api/panorama.php?h=<ハッシュ>(自分の配信だけ。CSP の img-src 'self')
 * - 表示は WebGL(上下も見られる・指やホイールで拡大)。WebGL が使えない端末では、横に見回すだけの 2D の canvas に落とす(アプリと同じ見え方)
 * - 外の部品は使わない(Pannellum などを置くと、部品の更新を追う手間が増える。利用者の答え「自分で書く」)
 * - 写真の向き(heading)があれば「地図の上」の向きを針で出す。heading は写真の真ん中が地図のどの向きか(地図の上 = 0°・時計回り)
 * - 文は日本語で書き、英語は Main/i18n.js の辞書が置き換える
 * - 計算(KmPanoramaMath)は DOM に触らない純粋な関数。node で試す(scripts/js/panorama.test.js)
 */
(function (root) {
    'use strict';

    const PITCH_LIMIT = 85;
    const FOV_MIN = 30;
    const FOV_MAX = 100;
    const FOV_START = 75;
    /** 写真を GPU に載せる大きさの上限(横)。端末の上限(MAX_TEXTURE_SIZE)と小さい方 */
    const TEXTURE_MAX = 8192;
    const KEY_STEP = 5;

    const math = {
        clampPitch(pitch) {
            return Math.max(-PITCH_LIMIT, Math.min(PITCH_LIMIT, Number(pitch) || 0));
        },
        clampFov(fov) {
            return Math.max(FOV_MIN, Math.min(FOV_MAX, Number(fov) || FOV_START));
        },
        /** 0 以上 360 未満へ */
        wrapYaw(yaw) {
            const value = (Number(yaw) || 0) % 360;
            return value < 0 ? value + 360 : value;
        },
        /**
         * 指(マウス)を動かした量 → 向きの変わる量。**写真を掴んで動かす**ので、右へ動かすと左を向く。
         * 1 px あたりの角度は、縦の視野 ÷ 画面の高さ
         */
        dragDelta(dx, dy, fov, height) {
            const perPx = math.clampFov(fov) / Math.max(1, Number(height) || 1);
            return { yaw: -dx * perPx, pitch: dy * perPx };
        },
        /** 2 本の指の間隔の比 → 新しい視野(広げると拡大 = 視野が狭くなる) */
        pinchFov(fov, startDistance, distance) {
            if (!(startDistance > 0) || !(distance > 0)) return math.clampFov(fov);
            return math.clampFov(fov * (startDistance / distance));
        },
        /** ホイール 1 回ぶん(deltaY)→ 新しい視野 */
        wheelFov(fov, deltaY) {
            return math.clampFov(fov * Math.exp((Number(deltaY) || 0) * 0.0015));
        },
        /** いま向いている方角(地図の上 = 0°・時計回り)。写真の向きが無ければ null */
        facing(heading, yaw) {
            if (heading === null || heading === undefined || !Number.isFinite(Number(heading))) return null;
            return math.wrapYaw(Number(heading) + Number(yaw || 0));
        },
        /** 針(地図の上を指す)を回す角度。向いている方角の逆 */
        needleRotation(heading, yaw) {
            const facing = math.facing(heading, yaw);
            return facing === null ? null : math.wrapYaw(-facing);
        },
        /** GPU に載せる大きさ。横:縦 = 2:1 を保ち、上限を超えたら縮める */
        textureSize(width, height, maxSize) {
            const limit = Math.max(256, Math.min(TEXTURE_MAX, Number(maxSize) || TEXTURE_MAX));
            const w = Math.max(1, Number(width) || 1);
            const h = Math.max(1, Number(height) || 1);
            if (w <= limit && h <= limit) return { width: w, height: h, scaled: false };
            const scale = Math.min(limit / w, limit / h);
            return { width: Math.max(1, Math.floor(w * scale)), height: Math.max(1, Math.floor(h * scale)), scaled: true };
        },
        /** 写真の取り先。ハッシュは 64 字の 16 進だけ通す(ほかは null) */
        photoUrl(hash) {
            return /^[0-9a-f]{64}$/.test(String(hash || '')) ? '/api/panorama.php?h=' + hash : null;
        },
    };

    root.KmPanoramaMath = math;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = math;
        return;
    }

    const VERTEX = `
attribute vec2 aPos;
varying vec2 vPos;
void main() {
    vPos = aPos;
    gl_Position = vec4(aPos, 0.0, 1.0);
}`;
    // 画面の 1 点ごとに、見ている向き(yaw・pitch)と視野から光の向きを出し、正距円筒の写真の位置を引く(球の網は作らない)
    const FRAGMENT = `
#ifdef GL_FRAGMENT_PRECISION_HIGH
precision highp float;
#else
precision mediump float;
#endif
uniform sampler2D uTex;
uniform float uYaw;
uniform float uPitch;
uniform float uTanHalf;
uniform float uAspect;
varying vec2 vPos;
const float PI = 3.14159265358979;
void main() {
    vec3 d = normalize(vec3(vPos.x * uTanHalf * uAspect, vPos.y * uTanHalf, 1.0));
    float cp = cos(uPitch);
    float sp = sin(uPitch);
    d = vec3(d.x, d.y * cp + d.z * sp, -d.y * sp + d.z * cp);
    float cy = cos(uYaw);
    float sy = sin(uYaw);
    d = vec3(d.x * cy + d.z * sy, d.y, -d.x * sy + d.z * cy);
    float lon = atan(d.x, d.z);
    float lat = asin(clamp(d.y, -1.0, 1.0));
    vec2 uv = vec2(fract(lon / (2.0 * PI) + 0.5), 0.5 - lat / PI);
    gl_FragColor = texture2D(uTex, uv);
}`;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    /** WebGL の描き手。作れなければ null */
    function createGlRenderer(canvas) {
        let gl = null;
        try {
            gl = canvas.getContext('webgl', { antialias: false, preserveDrawingBuffer: false })
                || canvas.getContext('experimental-webgl');
        } catch (error) {
            gl = null;
        }
        if (!gl) return null;
        const compile = (type, source) => {
            const shader = gl.createShader(type);
            gl.shaderSource(shader, source);
            gl.compileShader(shader);
            return gl.getShaderParameter(shader, gl.COMPILE_STATUS) ? shader : null;
        };
        const vs = compile(gl.VERTEX_SHADER, VERTEX);
        const fs = compile(gl.FRAGMENT_SHADER, FRAGMENT);
        if (!vs || !fs) return null;
        const program = gl.createProgram();
        gl.attachShader(program, vs);
        gl.attachShader(program, fs);
        gl.linkProgram(program);
        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) return null;
        gl.useProgram(program);
        const buffer = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
        const aPos = gl.getAttribLocation(program, 'aPos');
        gl.enableVertexAttribArray(aPos);
        gl.vertexAttribPointer(aPos, 2, gl.FLOAT, false, 0, 0);
        const uniforms = {
            yaw: gl.getUniformLocation(program, 'uYaw'),
            pitch: gl.getUniformLocation(program, 'uPitch'),
            tanHalf: gl.getUniformLocation(program, 'uTanHalf'),
            aspect: gl.getUniformLocation(program, 'uAspect'),
        };
        const texture = gl.createTexture();
        const maxSize = Number(gl.getParameter(gl.MAX_TEXTURE_SIZE)) || 4096;
        let ready = false;
        return {
            kind: 'webgl',
            maxSize: maxSize,
            setImage(source) {
                gl.bindTexture(gl.TEXTURE_2D, texture);
                // 2 の累乗でない大きさでも載るように、端は伸ばすだけ・縮小の段(mipmap)は作らない
                gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
                gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
                gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
                gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
                gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, source);
                ready = true;
            },
            /** 写真を替えるときに前の写真を消す(読めなかったときに前の写真が残って見えないように) */
            clear() {
                ready = false;
            },
            draw(view) {
                gl.viewport(0, 0, canvas.width, canvas.height);
                gl.clearColor(0, 0, 0, 1);
                gl.clear(gl.COLOR_BUFFER_BIT);
                if (!ready) return;
                gl.uniform1f(uniforms.yaw, view.yaw * Math.PI / 180);
                gl.uniform1f(uniforms.pitch, view.pitch * Math.PI / 180);
                gl.uniform1f(uniforms.tanHalf, Math.tan(view.fov * Math.PI / 360));
                gl.uniform1f(uniforms.aspect, canvas.width / Math.max(1, canvas.height));
                gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
            },
            dispose() {
                gl.deleteTexture(texture);
                gl.deleteBuffer(buffer);
                gl.deleteProgram(program);
                const lose = gl.getExtension('WEBGL_lose_context');
                if (lose) lose.loseContext();
            },
        };
    }

    /** 2D の描き手(WebGL が無いとき)。横に見回すだけ。上下は見られない */
    function createFlatRenderer(canvas) {
        const ctx = canvas.getContext('2d');
        let image = null;
        return {
            kind: 'flat',
            maxSize: TEXTURE_MAX,
            setImage(source) {
                image = source;
            },
            clear() {
                image = null;
            },
            draw(view) {
                ctx.fillStyle = '#000';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                if (!image) return;
                const iw = image.width;
                const ih = image.height;
                // 縦の視野で倍率を決める(写真の縦 = 180°)
                const scale = (canvas.height / ih) * (180 / view.fov) * 0.6;
                const drawW = iw * scale;
                const drawH = ih * scale;
                const top = (canvas.height - drawH) / 2;
                const centerX = (math.wrapYaw(view.yaw + 180) / 360) * drawW;
                let left = canvas.width / 2 - centerX;
                while (left > 0) left -= drawW;
                for (let x = left; x < canvas.width; x += drawW) ctx.drawImage(image, x, top, drawW, drawH);
            },
            dispose() {
                image = null;
            },
        };
    }

    function loadImage(url) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.decoding = 'async';
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error('load'));
            image.src = url;
        });
    }

    /** 大きすぎる写真は 2D の canvas で縮めてから載せる */
    function fitImage(image, maxSize) {
        const size = math.textureSize(image.naturalWidth || image.width, image.naturalHeight || image.height, maxSize);
        if (!size.scaled) return image;
        const canvas = document.createElement('canvas');
        canvas.width = size.width;
        canvas.height = size.height;
        canvas.getContext('2d').drawImage(image, 0, 0, size.width, size.height);
        return canvas;
    }

    let current = null;

    /**
     * 開く。photos は [{sha256, heading}](地図のデータの node.panoramas)。
     * onClose は閉じたあとに呼ぶ(地図編集で地点の窓を開き直す)
     */
    function open(options) {
        const photos = (options && Array.isArray(options.photos) ? options.photos : []).filter((p) => math.photoUrl(p && p.sha256));
        if (photos.length === 0) return false;
        if (current) current.close();

        const returnFocus = (options && options.returnFocus) || document.activeElement;
        const dialog = el('div', 'km-pano');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', '360° 写真');
        const canvas = el('canvas', 'km-pano-canvas');
        canvas.tabIndex = 0;
        canvas.setAttribute('aria-label', '360° 写真。ドラッグか矢印のキーで見回す');
        const bar = el('div', 'km-pano-bar');
        const title = el('span', 'km-pano-title', (options && options.title) || '');
        const count = el('span', 'km-pano-count');
        const prev = el('button', 'km-pano-btn km-pano-prev', '‹');
        prev.type = 'button';
        prev.setAttribute('aria-label', '前の写真');
        const next = el('button', 'km-pano-btn km-pano-next', '›');
        next.type = 'button';
        next.setAttribute('aria-label', '次の写真');
        const close = el('button', 'km-pano-btn km-pano-close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', '閉じる');
        bar.append(title, count, prev, next, close);
        const compass = el('div', 'km-pano-compass');
        const needle = el('span', 'km-pano-needle', '↑');
        needle.setAttribute('aria-hidden', 'true');
        compass.append(needle, el('span', 'km-pano-compass-label', '地図の上'));
        const hint = el('p', 'km-pano-hint', 'ドラッグで見回す・ピンチやホイールで拡大');
        const status = el('p', 'km-pano-status');
        status.setAttribute('role', 'status');
        dialog.append(canvas, bar, compass, hint, status);
        document.body.appendChild(dialog);
        document.body.classList.add('km-pano-open');

        let renderer = createGlRenderer(canvas);
        if (!renderer) {
            renderer = createFlatRenderer(canvas);
            hint.textContent = 'ドラッグで横に見回す(この端末では上下は見られません)';
        }
        const view = { yaw: 0, pitch: 0, fov: FOV_START };
        let index = Math.max(0, Math.min(photos.length - 1, Number(options && options.start) || 0));
        let frame = 0;
        let loadToken = 0;
        const pointers = new Map();
        let pinchStart = null;

        const paintCompass = () => {
            const rotation = math.needleRotation(photos[index].heading, view.yaw);
            compass.hidden = rotation === null;
            if (rotation !== null) needle.style.transform = 'rotate(' + rotation.toFixed(1) + 'deg)';
        };
        const draw = () => {
            frame = 0;
            renderer.draw(view);
            paintCompass();
        };
        const requestDraw = () => {
            if (!frame) frame = window.requestAnimationFrame(draw);
        };
        const resize = () => {
            const ratio = Math.min(window.devicePixelRatio || 1, 2);
            const width = Math.max(1, Math.round(canvas.clientWidth * ratio));
            const height = Math.max(1, Math.round(canvas.clientHeight * ratio));
            if (canvas.width !== width || canvas.height !== height) {
                canvas.width = width;
                canvas.height = height;
            }
            requestDraw();
        };

        const show = async (at) => {
            index = (at + photos.length) % photos.length;
            count.textContent = photos.length > 1 ? (index + 1) + ' / ' + photos.length : '';
            prev.hidden = photos.length < 2;
            next.hidden = photos.length < 2;
            view.yaw = 0;
            view.pitch = 0;
            view.fov = FOV_START;
            renderer.clear();
            requestDraw();
            status.textContent = '写真を読み込んでいます…';
            const token = ++loadToken;
            try {
                const image = await loadImage(math.photoUrl(photos[index].sha256));
                if (token !== loadToken || !current) return;
                renderer.setImage(fitImage(image, renderer.maxSize));
                status.textContent = '';
            } catch (error) {
                if (token !== loadToken) return;
                status.textContent = '写真を読み込めませんでした。通信の状態を確かめてから開き直してください。';
            }
            requestDraw();
        };

        const turn = (dYaw, dPitch) => {
            view.yaw = math.wrapYaw(view.yaw + dYaw);
            view.pitch = math.clampPitch(view.pitch + dPitch);
            requestDraw();
        };
        const zoomTo = (fov) => {
            view.fov = math.clampFov(fov);
            requestDraw();
        };

        const distance = () => {
            const points = Array.from(pointers.values());
            return points.length < 2 ? 0 : Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y);
        };
        canvas.addEventListener('pointerdown', (event) => {
            canvas.setPointerCapture(event.pointerId);
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            if (pointers.size === 2) pinchStart = { distance: distance(), fov: view.fov };
            canvas.classList.add('is-dragging');
        });
        canvas.addEventListener('pointermove', (event) => {
            const last = pointers.get(event.pointerId);
            if (!last) return;
            const point = { x: event.clientX, y: event.clientY };
            pointers.set(event.pointerId, point);
            if (pointers.size >= 2 && pinchStart) {
                zoomTo(math.pinchFov(pinchStart.fov, pinchStart.distance, distance()));
                return;
            }
            const delta = math.dragDelta(point.x - last.x, point.y - last.y, view.fov, canvas.clientHeight);
            turn(delta.yaw, delta.pitch);
        });
        const release = (event) => {
            pointers.delete(event.pointerId);
            if (pointers.size < 2) pinchStart = null;
            if (pointers.size === 0) canvas.classList.remove('is-dragging');
        };
        canvas.addEventListener('pointerup', release);
        canvas.addEventListener('pointercancel', release);
        canvas.addEventListener('wheel', (event) => {
            event.preventDefault();
            zoomTo(math.wheelFov(view.fov, event.deltaY));
        }, { passive: false });

        const focusables = () => [canvas, prev, next, close].filter((node) => !node.hidden);
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                api.close();
                return;
            }
            if (event.key === 'Tab') {
                // 窓の外へ出さない
                const list = focusables();
                const at = list.indexOf(document.activeElement);
                event.preventDefault();
                list[(at + (event.shiftKey ? list.length - 1 : 1)) % list.length].focus();
                return;
            }
            if (event.target !== canvas) return;
            const moves = {
                ArrowLeft: [-KEY_STEP, 0], ArrowRight: [KEY_STEP, 0], ArrowUp: [0, KEY_STEP], ArrowDown: [0, -KEY_STEP],
            };
            if (moves[event.key]) {
                event.preventDefault();
                turn(moves[event.key][0], moves[event.key][1]);
            } else if (event.key === '+' || event.key === '=') {
                event.preventDefault();
                zoomTo(view.fov - KEY_STEP);
            } else if (event.key === '-') {
                event.preventDefault();
                zoomTo(view.fov + KEY_STEP);
            }
        };
        dialog.addEventListener('keydown', onKey);
        prev.addEventListener('click', () => show(index - 1));
        next.addEventListener('click', () => show(index + 1));
        close.addEventListener('click', () => api.close());
        window.addEventListener('resize', resize);

        const api = {
            close() {
                if (current !== api) return;
                current = null;
                loadToken++;
                if (frame) window.cancelAnimationFrame(frame);
                window.removeEventListener('resize', resize);
                renderer.dispose();
                dialog.remove();
                document.body.classList.remove('km-pano-open');
                if (returnFocus && typeof returnFocus.focus === 'function' && document.contains(returnFocus)) returnFocus.focus();
                if (options && typeof options.onClose === 'function') options.onClose();
            },
            /** 試験・確かめ用(いまの向き) */
            view() {
                return { yaw: view.yaw, pitch: view.pitch, fov: view.fov, renderer: renderer.kind, index: index };
            },
        };
        current = api;
        resize();
        show(index);
        canvas.focus();
        return true;
    }

    root.KmPanorama = {
        open: open,
        close() {
            if (current) current.close();
        },
        current() {
            return current;
        },
    };
})(typeof window !== 'undefined' ? window : globalThis);
