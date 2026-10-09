"""
AR の撮影の深さから、部屋の点群(色付き)を作る(2026-10-09、利用者の発想「ARCore の深さから Blender で大体のモデルを作る」)。

管理アプリの AR 実測で「画像も保存」を選ぶと、1 枚ごとに写真・深さ(16bit の PNG。mm)・カメラの位置と向き・内部の値が残る。
管理画面「AR の撮影」で落とした zip を scripts\\ar-blur-faces.ps1 でぼかしたフォルダに使う。

使い方:
  Blender の中で(点群を Blender の物として作る。Blender 4.2 以上):
    blender.exe -P ar-depth-cloud.py -- --dir <フォルダ> [--depth-zip <zip>] [--out room.blend]
    blender.exe -b -P ar-depth-cloud.py -- --dir <フォルダ> --out room.blend     (画面なしで作って保存)
  Blender なしで(numpy だけ。PLY と画像を書く。色は Pillow があれば付ける):
    python ar-depth-cloud.py --dir <フォルダ>

  --depth-zip <zip>   深さを zip から読む(展開しない。depth\\ を消してしまったフォルダでも使える)。
                      zip から読むのは depth/*.png だけで、ぼかす前の写真は取り出さない
  --preview           深さを目で見られる色の画像(近い = 赤 〜 遠い = 青)にして depth_preview\\ に書く
  --max-range 5       これより遠い深さは使わない(m)。ARCore の深さは 5 m くらいまでが実用
  --voxel 0.05        間引きの升目(m)
  --min-hits 2        この枚数以上の画像から当たった升目だけ残す(1 枚だけの外れを消す)
  --ply <path>        PLY の書き先(既定 <フォルダ>\\pointcloud\\points.ply)
  --out <path>        (Blender のとき)できた場面を .blend に保存する
  --no-color          写真から色を取らない(速い)

出すもの(<フォルダ>\\pointcloud\\):
  points.ply      点群(東・北・上の m。色付き)。CloudCompare・RealityScan・Blender で読める
  top_view.png    真上から見た点の濃さ(5 cm/px。北が上)。壁が濃い線になる。壁をなぞる目安
  summary.txt     使った画像・飛ばした画像とその理由・点の数・床と天井の高さ

座標:
  survey.json に重ね方(alignment)があれば、地図と同じ東・北(m。原点は最初に印を付けた地点)と、
  上(m。最初の印のときのカメラの高さが 0)。scripts\\ar-realityscan-prep.ps1 と同じ式。
  重ね方が無ければ ARCore の世界のまま(東 = x・北 = -z・上 = y。原点は最初の画像のカメラ)。

**顔をぼかす前の写真で色を付けないこと**(点群に人の姿が残る)。--dir はぼかしたフォルダを渡す。
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import struct
import sys
import zipfile
import zlib
from dataclasses import dataclass, field
from pathlib import Path

import numpy as np

try:  # Blender の中で動いているときだけ
    import bpy  # type: ignore
except ImportError:  # pragma: no cover - Blender の外
    bpy = None


# ---------------------------------------------------------------- PNG

def read_png16(data: bytes) -> np.ndarray:
    """16bit グレーの PNG(アプリの encodeGray16Png)を (高さ, 幅) の uint16 にする。行のフィルター 0〜4 に対応。"""
    if data[:8] != b"\x89PNG\r\n\x1a\n":
        raise ValueError("PNG ではありません")
    pos = 8
    width = height = 0
    idat = bytearray()
    while pos + 8 <= len(data):
        (length,) = struct.unpack(">I", data[pos:pos + 4])
        kind = data[pos + 4:pos + 8]
        body = data[pos + 8:pos + 8 + length]
        if kind == b"IHDR":
            width, height, depth, color, _, _, interlace = struct.unpack(">IIBBBBB", body[:13])
            if depth != 16 or color != 0 or interlace != 0:
                raise ValueError(f"16bit グレーの PNG ではありません(深さ {depth}・色の型 {color})")
        elif kind == b"IDAT":
            idat += body
        elif kind == b"IEND":
            break
        pos += 12 + length
    if width <= 0 or height <= 0:
        raise ValueError("PNG の大きさがありません")
    raw = zlib.decompress(bytes(idat))
    stride = width * 2
    if len(raw) < height * (stride + 1):
        raise ValueError("PNG の中身が足りません")
    out = np.empty((height, stride), dtype=np.uint8)
    prev = np.zeros(stride, dtype=np.int32)
    for row in range(height):
        start = row * (stride + 1)
        kind = raw[start]
        line = np.frombuffer(raw, dtype=np.uint8, count=stride, offset=start + 1).astype(np.int32)
        if kind == 0:
            cur = line
        elif kind == 2:
            cur = (line + prev) & 255
        else:
            # Sub・Average・Paeth は左隣(2 バイト前)に頼るので 1 バイトずつ
            cur = np.empty(stride, dtype=np.int32)
            for x in range(stride):
                a = int(cur[x - 2]) if x >= 2 else 0
                b = int(prev[x])
                c = int(prev[x - 2]) if x >= 2 else 0
                if kind == 1:
                    pred = a
                elif kind == 3:
                    pred = (a + b) // 2
                elif kind == 4:
                    p = a + b - c
                    pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                    pred = a if pa <= pb and pa <= pc else (b if pb <= pc else c)
                else:
                    raise ValueError(f"知らない行のフィルター {kind}")
                cur[x] = (int(line[x]) + pred) & 255
        out[row] = cur
        prev = cur
    return out.view(">u2").reshape(height, width).astype(np.uint16)


def _png_chunk(kind: bytes, body: bytes) -> bytes:
    return struct.pack(">I", len(body)) + kind + body + struct.pack(">I", zlib.crc32(kind + body) & 0xFFFFFFFF)


def encode_png8(image: np.ndarray) -> bytes:
    """8bit の PNG(グレー (h, w) か RGB (h, w, 3))。"""
    image = np.ascontiguousarray(image, dtype=np.uint8)
    height, width = image.shape[:2]
    color = 0 if image.ndim == 2 else 2
    rows = image.reshape(height, -1)
    raw = b"".join(b"\x00" + rows[r].tobytes() for r in range(height))
    header = struct.pack(">IIBBBBB", width, height, 8, color, 0, 0, 0)
    return b"\x89PNG\r\n\x1a\n" + _png_chunk(b"IHDR", header) + _png_chunk(b"IDAT", zlib.compress(raw, 6)) + _png_chunk(b"IEND", b"")


# ---------------------------------------------------------------- 撮影の読み込み

@dataclass
class Frame:
    name: str
    depth_name: str | None
    t_ms: int
    position: np.ndarray       # (3,) ARCore の世界の m
    rotation: np.ndarray       # (3, 3) カメラ → 世界
    fx: float
    fy: float
    cx: float
    cy: float
    width: int
    height: int


def quat_to_matrix(q) -> np.ndarray:
    """(x, y, z, w) の四元数を回転行列にする。"""
    x, y, z, w = (float(v) for v in q)
    n = math.sqrt(x * x + y * y + z * z + w * w) or 1.0
    x, y, z, w = x / n, y / n, z / n, w / n
    return np.array([
        [1 - 2 * (y * y + z * z), 2 * (x * y - z * w), 2 * (x * z + y * w)],
        [2 * (x * y + z * w), 1 - 2 * (x * x + z * z), 2 * (y * z - x * w)],
        [2 * (x * z - y * w), 2 * (y * z + x * w), 1 - 2 * (x * x + y * y)],
    ], dtype=np.float64)


def load_frames(frames_json: list) -> list[Frame]:
    frames = []
    for f in frames_json:
        k = f["intrinsics"]
        frames.append(Frame(
            name=str(f["name"]),
            depth_name=f.get("depth"),
            t_ms=int(f["timestampMillis"]),
            position=np.array(f["pose"]["t"], dtype=np.float64),
            rotation=quat_to_matrix(f["pose"]["q"]),
            fx=float(k["fx"]), fy=float(k["fy"]), cx=float(k["cx"]), cy=float(k["cy"]),
            width=int(k["width"]), height=int(k["height"]),
        ))
    return frames


class DepthSource:
    """深さの PNG をフォルダの depth\\ か、zip の depth/ から読む(zip の写真は取り出さない)。"""

    def __init__(self, folder: Path, depth_zip: Path | None = None):
        self.folder = folder
        self.zip = zipfile.ZipFile(depth_zip) if depth_zip else None

    def read(self, name: str) -> bytes | None:
        if self.zip is not None:
            try:
                return self.zip.read(f"depth/{name}")
            except KeyError:
                return None
        path = self.folder / "depth" / name
        return path.read_bytes() if path.is_file() else None

    def close(self):
        if self.zip is not None:
            self.zip.close()


# ---------------------------------------------------------------- 深さ → 3D の点

def usable_depth_mask(depth_m: np.ndarray, min_range: float, max_range: float, edge_ratio: float) -> np.ndarray:
    """使う画素: 範囲内で、隣との差が距離の [edge_ratio] 以下(物の縁は物と物の間に浮く点になるので外す)。"""
    valid = (depth_m >= min_range) & (depth_m <= max_range)
    edge = np.zeros_like(valid)
    for dy, dx in ((0, 1), (1, 0)):
        a = depth_m[: depth_m.shape[0] - dy, : depth_m.shape[1] - dx]
        b = depth_m[dy:, dx:]
        both = (a > 0) & (b > 0)
        jump = both & (np.abs(a - b) > edge_ratio * np.minimum(a, b))
        edge[: depth_m.shape[0] - dy, : depth_m.shape[1] - dx] |= jump
        edge[dy:, dx:] |= jump
    return valid & ~edge


def backproject(depth_mm: np.ndarray, frame: Frame, min_range: float = 0.2, max_range: float = 5.0,
                edge_ratio: float = 0.1) -> tuple[np.ndarray, np.ndarray]:
    """
    深さの画素を ARCore の世界の点にする。戻りは (点 (N, 3), 写真の画素の位置 (N, 2))。

    Camera.getPose は OpenGL のカメラ(+x 右・+y 上・-z が前。右と上は画像の読み出しの向き)。
    深さの画像は写真と同じ向きで小さいので、内部の値を比で直す。
    """
    h, w = depth_mm.shape
    depth_m = depth_mm.astype(np.float64) / 1000.0
    mask = usable_depth_mask(depth_m, min_range, max_range, edge_ratio)
    sx = w / frame.width
    sy = h / frame.height
    fx, fy, cx, cy = frame.fx * sx, frame.fy * sy, frame.cx * sx, frame.cy * sy
    vs, us = np.nonzero(mask)
    d = depth_m[vs, us]
    u = us + 0.5
    v = vs + 0.5
    cam = np.stack([(u - cx) / fx * d, -(v - cy) / fy * d, -d], axis=1)
    world = cam @ frame.rotation.T + frame.position
    pixels = np.stack([u / sx, v / sy], axis=1)
    return world, pixels


# ---------------------------------------------------------------- 地図の座標へ

@dataclass
class Placement:
    """ARCore の世界 → 東・北・上(m)。"""
    segments: list = field(default_factory=list)
    base_y: float = 0.0
    origin: np.ndarray | None = None   # 重ね方が無いときの原点(最初の画像のカメラ)
    aligned: bool = False

    def to_enu(self, t_ms: int, world: np.ndarray) -> np.ndarray | None:
        if self.aligned:
            seg = next((s for s in self.segments if int(s["fromT"]) <= t_ms <= int(s["toT"])), None)
            if seg is None:
                return None
            # ar-realityscan-prep.ps1 と同じ式: a = x・b = -z を回して、原点の東・北を足す
            r = math.radians(float(seg["rotationDegrees"]))
            a = world[:, 0]
            b = -world[:, 2]
            east = math.cos(r) * a - math.sin(r) * b + float(seg["east0"])
            north = math.sin(r) * a + math.cos(r) * b + float(seg["north0"])
            up = world[:, 1] - self.base_y
            return np.stack([east, north, up], axis=1)
        o = self.origin if self.origin is not None else np.zeros(3)
        return np.stack([world[:, 0] - o[0], -(world[:, 2] - o[2]), world[:, 1] - o[1]], axis=1)


def make_placement(survey: dict, frames: list[Frame]) -> Placement:
    alignment = survey.get("alignment") or {}
    segments = alignment.get("segments") or []
    if segments:
        base = alignment.get("originArY")
        base_y = float(base) if base is not None else (float(frames[0].position[1]) if frames else 0.0)
        return Placement(segments=segments, base_y=base_y, aligned=True)
    return Placement(origin=frames[0].position.copy() if frames else np.zeros(3), aligned=False)


# ---------------------------------------------------------------- 間引き・床と天井・真上の画像

def voxel_downsample(points: np.ndarray, colors: np.ndarray | None, frame_ids: np.ndarray,
                     voxel: float, min_hits: int) -> tuple[np.ndarray, np.ndarray | None, np.ndarray]:
    """升目ごとに 1 点(位置と色は平均)。[min_hits] 枚以上の画像から当たった升目だけ残す。戻りは (点, 色, 当たった枚数)。"""
    if len(points) == 0:
        return points.reshape(0, 3), None if colors is None else colors.reshape(0, 3), np.zeros(0, dtype=np.int64)
    keys = np.floor(points / voxel).astype(np.int64)
    _, inverse = np.unique(keys, axis=0, return_inverse=True)
    inverse = inverse.reshape(-1)
    count = np.bincount(inverse)
    pairs = np.unique(np.stack([inverse, frame_ids.astype(np.int64)], axis=1), axis=0)
    hits = np.bincount(pairs[:, 0], minlength=len(count))
    keep = hits >= min_hits
    mean = np.stack([np.bincount(inverse, weights=points[:, i]) / count for i in range(3)], axis=1)
    out_colors = None
    if colors is not None:
        out_colors = np.stack([np.bincount(inverse, weights=colors[:, i]) / count for i in range(3)], axis=1)[keep]
    return mean[keep], out_colors, hits[keep]


def estimate_floor_ceiling(up: np.ndarray, bin_m: float = 0.05) -> tuple[float | None, float | None]:
    """
    床と天井の高さ(上の m)。水平な面は同じ高さに点が固まるので、点の数の山で見立てる。
    床はカメラ(0)より 0.5 m 以上下、天井は 0.5 m 以上上の、いちばん高い山。
    山と呼ぶのは、点が全体の 2% 以上で、かつその範囲の普通の升(中央値)の 3 倍以上のとき
    (壁の点は上下に一様に並ぶので、一様なだけのところを床・天井と見立てない)。
    """
    if len(up) == 0:
        return None, None
    lo, hi = float(np.min(up)), float(np.max(up))
    bins = np.arange(math.floor(lo / bin_m) * bin_m, hi + 2 * bin_m, bin_m)
    hist, edges = np.histogram(up, bins=bins)
    centers = (edges[:-1] + edges[1:]) / 2

    def peak(selector):
        idx = np.nonzero(selector)[0]
        if len(idx) == 0:
            return None
        best = idx[np.argmax(hist[idx])]
        typical = float(np.median(hist[idx]))
        return float(centers[best]) if hist[best] >= max(0.02 * len(up), 3 * typical) else None

    return peak(centers < -0.5), peak(centers > 0.5)


def top_view(points: np.ndarray, floor: float | None, ceiling: float | None, res: float = 0.05) -> tuple[np.ndarray, tuple[float, float, float, float]]:
    """
    真上から見た点の濃さ(8bit。北が上)。床と天井の点は外す(壁と家具だけが濃く残る)。
    戻りは (画像, (西の端, 南の端, 東の端, 北の端))。
    """
    sel = points
    if floor is not None:
        sel = sel[sel[:, 2] > floor + 0.2]
    if ceiling is not None:
        sel = sel[sel[:, 2] < ceiling - 0.2]
    if len(sel) == 0:
        sel = points
    if len(sel) == 0:
        return np.zeros((1, 1), dtype=np.uint8), (0.0, 0.0, res, res)
    west, south = float(np.min(sel[:, 0])) - res, float(np.min(sel[:, 1])) - res
    east, north = float(np.max(sel[:, 0])) + res, float(np.max(sel[:, 1])) + res
    width = max(1, int(math.ceil((east - west) / res)))
    height = max(1, int(math.ceil((north - south) / res)))
    cols = np.clip(((sel[:, 0] - west) / res).astype(int), 0, width - 1)
    rows = np.clip(((north - sel[:, 1]) / res).astype(int), 0, height - 1)
    grid = np.zeros((height, width), dtype=np.float64)
    np.add.at(grid, (rows, cols), 1)
    scaled = np.log1p(grid)
    if scaled.max() > 0:
        scaled = scaled / scaled.max()
    image = (255 - scaled * 255).astype(np.uint8)   # 白地に黒(紙の図面のように)
    return image, (west, south, west + width * res, south + height * res)


def depth_preview(depth_mm: np.ndarray, max_range: float) -> np.ndarray:
    """深さを目で見られる色にする(近い = 赤 〜 遠い = 青。0 = 黒)。"""
    d = depth_mm.astype(np.float64) / 1000.0
    x = np.clip(d / max_range, 0, 1)
    x = 1 - x   # 近いほど 1(赤)
    r = np.clip(np.minimum(4 * x - 1.5, -4 * x + 4.5), 0, 1)
    g = np.clip(np.minimum(4 * x - 0.5, -4 * x + 3.5), 0, 1)
    b = np.clip(np.minimum(4 * x + 0.5, -4 * x + 2.5), 0, 1)
    rgb = (np.stack([r, g, b], axis=-1) * 255).astype(np.uint8)
    rgb[d <= 0] = 0
    return rgb


def write_ply(path: Path, points: np.ndarray, colors: np.ndarray | None):
    """点群を PLY(バイナリ)に書く。色は 0〜1。"""
    n = len(points)
    header = ["ply", "format binary_little_endian 1.0", "comment KosenMap AR depth cloud (east, north, up in metres)",
              f"element vertex {n}", "property float x", "property float y", "property float z"]
    if colors is not None:
        header += ["property uchar red", "property uchar green", "property uchar blue"]
    header.append("end_header")
    if colors is not None:
        rec = np.empty(n, dtype=[("x", "<f4"), ("y", "<f4"), ("z", "<f4"), ("r", "u1"), ("g", "u1"), ("b", "u1")])
        rgb = np.clip(np.round(colors * 255), 0, 255).astype(np.uint8)
        rec["r"], rec["g"], rec["b"] = rgb[:, 0], rgb[:, 1], rgb[:, 2]
    else:
        rec = np.empty(n, dtype=[("x", "<f4"), ("y", "<f4"), ("z", "<f4")])
    rec["x"], rec["y"], rec["z"] = points[:, 0], points[:, 1], points[:, 2]
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "wb") as fh:
        fh.write(("\n".join(header) + "\n").encode("ascii"))
        fh.write(rec.tobytes())


# ---------------------------------------------------------------- 写真の色

def _image_sampler_blender(path: Path):
    image = bpy.data.images.load(str(path), check_existing=False)
    try:
        w, h = image.size
        buf = np.empty(w * h * 4, dtype=np.float32)
        image.pixels.foreach_get(buf)
        rgb = buf.reshape(h, w, 4)[::-1, :, :3]   # Blender の画素は下の行から
        return rgb.copy()
    finally:
        bpy.data.images.remove(image)


def _image_sampler_pillow(path: Path):
    try:
        from PIL import Image  # type: ignore
    except ImportError:
        return None
    with Image.open(path) as im:
        return np.asarray(im.convert("RGB"), dtype=np.float32) / 255.0


def load_image_rgb(path: Path):
    """写真を (高さ, 幅, 3) の 0〜1 にする。読めなければ None。"""
    if not path.is_file():
        return None
    if bpy is not None:
        return _image_sampler_blender(path)
    return _image_sampler_pillow(path)


# ---------------------------------------------------------------- 全体

@dataclass
class CloudResult:
    points: np.ndarray
    colors: np.ndarray | None
    hits: np.ndarray
    camera_path: np.ndarray
    floor: float | None
    ceiling: float | None
    aligned: bool
    stats: dict


def build_cloud(folder: Path, depth_zip: Path | None = None, *, max_range: float = 5.0, voxel: float = 0.05,
                min_hits: int = 2, with_color: bool = True, preview_dir: Path | None = None, log=print) -> CloudResult:
    frames_json = json.loads((folder / "frames.json").read_text(encoding="utf-8"))
    survey_path = folder / "survey.json"
    survey = json.loads(survey_path.read_text(encoding="utf-8")) if survey_path.is_file() else {}
    frames = load_frames(frames_json)
    placement = make_placement(survey, frames)
    source = DepthSource(folder, depth_zip)
    stats = {"frames": len(frames), "used": 0, "no_depth": 0, "empty": 0, "stale": 0, "outside_segment": 0, "raw_points": 0}
    all_points, all_colors, all_ids, camera_path = [], [], [], []
    previous_hash = None
    try:
        for index, frame in enumerate(frames):
            data = source.read(frame.depth_name) if frame.depth_name else None
            if data is None:
                stats["no_depth"] += 1
                continue
            digest = hashlib.sha256(data).digest()
            if digest == previous_hash:
                # 直前と同じ深さ = ARCore が深さを更新していない(10/8 に 85 枚続いた)
                stats["stale"] += 1
                continue
            previous_hash = digest
            depth = read_png16(data)
            if preview_dir is not None:
                preview_dir.mkdir(parents=True, exist_ok=True)
                (preview_dir / frame.depth_name).write_bytes(encode_png8(depth_preview(depth, max_range)))
            if not depth.any():
                stats["empty"] += 1
                continue
            world, pixels = backproject(depth, frame, max_range=max_range)
            enu = placement.to_enu(frame.t_ms, world)
            if enu is None:
                stats["outside_segment"] += 1
                continue
            cam = placement.to_enu(frame.t_ms, frame.position.reshape(1, 3))
            if cam is not None:
                camera_path.append(cam[0])
            stats["used"] += 1
            all_points.append(enu)
            all_ids.append(np.full(len(enu), index, dtype=np.int64))
            if with_color:
                rgb = load_image_rgb(folder / "images" / frame.name)
                if rgb is None:
                    with_color = False
                    all_colors = []
                    log("写真の色を取れません(Blender の外で Pillow が無い・写真が無い)。色なしで続けます")
                else:
                    h, w = rgb.shape[:2]
                    px = np.clip(pixels[:, 0].astype(int), 0, w - 1)
                    py = np.clip(pixels[:, 1].astype(int), 0, h - 1)
                    all_colors.append(rgb[py, px])
            if stats["used"] % 25 == 0:
                log(f"  {index + 1} / {len(frames)} 枚")
    finally:
        source.close()
    points = np.concatenate(all_points) if all_points else np.zeros((0, 3))
    colors = np.concatenate(all_colors) if (with_color and all_colors) else None
    ids = np.concatenate(all_ids) if all_ids else np.zeros(0, dtype=np.int64)
    stats["raw_points"] = int(len(points))
    points, colors, hits = voxel_downsample(points, colors, ids, voxel, min_hits)
    floor, ceiling = estimate_floor_ceiling(points[:, 2]) if len(points) else (None, None)
    return CloudResult(points, colors, hits, np.array(camera_path).reshape(-1, 3), floor, ceiling, placement.aligned, stats)


def summary_lines(result: CloudResult, folder: Path) -> list[str]:
    s = result.stats
    lines = [
        f"撮影: {folder.name}",
        f"座標: {'地図の東・北・上(m。原点は最初に印を付けた地点)' if result.aligned else 'ARCore の世界のまま(重ね方なし。原点は最初の画像のカメラ)'}",
        f"画像 {s['frames']} 枚のうち、使った {s['used']} 枚",
        f"  深さなし {s['no_depth']}・空 {s['empty']}・更新されていない(直前と同じ){s['stale']}・重ねられない区間 {s['outside_segment']}",
        f"点: {s['raw_points']} → 間引いて {len(result.points)}",
        f"床: {'見立てなし' if result.floor is None else f'{result.floor:+.2f} m'}・天井: {'見立てなし' if result.ceiling is None else f'{result.ceiling:+.2f} m'}"
        + ("" if result.floor is None or result.ceiling is None else f"(高さ {result.ceiling - result.floor:.2f} m)"),
    ]
    if len(result.points):
        lo = result.points.min(axis=0)
        hi = result.points.max(axis=0)
        lines.append(f"広さ: 東西 {hi[0] - lo[0]:.1f} m・南北 {hi[1] - lo[1]:.1f} m・上下 {hi[2] - lo[2]:.1f} m")
    return lines


# ---------------------------------------------------------------- Blender

def _new_plane(name: str, west: float, south: float, east: float, north: float, z: float):
    mesh = bpy.data.meshes.new(name)
    mesh.from_pydata([(west, south, z), (east, south, z), (east, north, z), (west, north, z)], [], [(0, 1, 2, 3)])
    mesh.uv_layers.new(name="UVMap")
    for loop, uv in zip(mesh.uv_layers[0].data, [(0, 0), (1, 0), (1, 1), (0, 1)]):
        loop.uv = uv
    mesh.update()
    return bpy.data.objects.new(name, mesh)


def _material(name: str):
    mat = bpy.data.materials.new(name)
    if bpy.app.version < (5, 0, 0):
        mat.use_nodes = True   # Blender 5 からは常に有効(書くと「6.0 で消える」と警告が出る)
    return mat


def _remove_default_cube():
    """起動したての場面にある立方体(8 頂点の「Cube」)だけを片付ける。ほかの物には触らない。"""
    cube = bpy.data.objects.get("Cube")
    if cube is not None and cube.type == "MESH" and len(cube.data.vertices) == 8 and len(bpy.data.objects) <= 3:
        bpy.data.objects.remove(cube, do_unlink=True)


def build_blender_scene(result: CloudResult, name: str, top_view_path: Path | None, extent):
    _remove_default_cube()
    collection = bpy.data.collections.new(f"AR 点群 {name}")
    bpy.context.scene.collection.children.link(collection)

    # 点群: 頂点だけのメッシュ + 色属性。物のモードで見えるよう、ジオメトリノードで点にする
    mesh = bpy.data.meshes.new(f"点群 {name}")
    mesh.vertices.add(len(result.points))
    mesh.vertices.foreach_set("co", result.points.astype(np.float32).ravel())
    if result.colors is not None:
        attr = mesh.color_attributes.new("Col", "FLOAT_COLOR", "POINT")
        rgba = np.concatenate([result.colors, np.ones((len(result.colors), 1))], axis=1).astype(np.float32)
        attr.data.foreach_set("color", rgba.ravel())
    mesh.update()
    cloud = bpy.data.objects.new(f"点群 {name}", mesh)
    collection.objects.link(cloud)

    mat = _material("AR 点群の色")
    nodes = mat.node_tree.nodes
    bsdf = next(n for n in nodes if n.type == "BSDF_PRINCIPLED")
    if result.colors is not None:
        attr_node = nodes.new("ShaderNodeAttribute")
        attr_node.attribute_name = "Col"
        mat.node_tree.links.new(attr_node.outputs["Color"], bsdf.inputs["Base Color"])

    tree = bpy.data.node_groups.new("AR 点群を点で表示", "GeometryNodeTree")
    tree.interface.new_socket("Geometry", in_out="INPUT", socket_type="NodeSocketGeometry")
    tree.interface.new_socket("Geometry", in_out="OUTPUT", socket_type="NodeSocketGeometry")
    group_in = tree.nodes.new("NodeGroupInput")
    group_out = tree.nodes.new("NodeGroupOutput")
    to_points = tree.nodes.new("GeometryNodeMeshToPoints")
    to_points.inputs["Radius"].default_value = 0.015
    set_mat = tree.nodes.new("GeometryNodeSetMaterial")
    set_mat.inputs["Material"].default_value = mat
    tree.links.new(group_in.outputs[0], to_points.inputs["Mesh"])
    tree.links.new(to_points.outputs["Points"], set_mat.inputs["Geometry"])
    tree.links.new(set_mat.outputs["Geometry"], group_out.inputs[0])
    modifier = cloud.modifiers.new("点として表示", "NODES")
    modifier.node_group = tree

    # 床と天井(見立て)。点を隠さないよう線だけで出す
    if len(result.points):
        lo = result.points.min(axis=0)
        hi = result.points.max(axis=0)
        for label, z in (("床(見立て)", result.floor), ("天井(見立て)", result.ceiling)):
            if z is not None:
                plane = _new_plane(label, lo[0], lo[1], hi[0], hi[1], z)
                plane.display_type = "WIRE"
                collection.objects.link(plane)

    # 真上の画像の板(床の高さに置く。壁をなぞる下敷き)
    if top_view_path is not None and top_view_path.is_file():
        west, south, east, north = extent
        z = (result.floor if result.floor is not None else float(result.points[:, 2].min()) if len(result.points) else 0.0) - 0.01
        board = _new_plane("真上の点の濃さ", west, south, east, north, z)
        board_mat = _material("真上の点の濃さ")
        image = bpy.data.images.load(str(top_view_path), check_existing=True)
        tex = board_mat.node_tree.nodes.new("ShaderNodeTexImage")
        tex.image = image
        tex.interpolation = "Closest"
        board_bsdf = next(n for n in board_mat.node_tree.nodes if n.type == "BSDF_PRINCIPLED")
        board_mat.node_tree.links.new(tex.outputs["Color"], board_bsdf.inputs["Base Color"])
        board.data.materials.append(board_mat)
        collection.objects.link(board)

    # カメラの道(線)
    if len(result.camera_path) >= 2:
        path_mesh = bpy.data.meshes.new("カメラの道")
        verts = [tuple(p) for p in result.camera_path]
        path_mesh.from_pydata(verts, [(i, i + 1) for i in range(len(verts) - 1)], [])
        path_mesh.update()
        collection.objects.link(bpy.data.objects.new("カメラの道", path_mesh))
    return cloud


# ---------------------------------------------------------------- 入口

def parse_args(argv: list[str]):
    parser = argparse.ArgumentParser(description="AR の撮影の深さから部屋の点群を作る")
    parser.add_argument("--dir", required=True, help="ar-blur-faces.ps1 でぼかしたフォルダ")
    parser.add_argument("--depth-zip", help="深さを読む zip(depth/*.png だけを読む)")
    parser.add_argument("--max-range", type=float, default=5.0)
    parser.add_argument("--voxel", type=float, default=0.05)
    parser.add_argument("--min-hits", type=int, default=2)
    parser.add_argument("--ply", help="PLY の書き先")
    parser.add_argument("--out", help="(Blender)場面を保存する .blend")
    parser.add_argument("--preview", action="store_true", help="深さを色の画像にして depth_preview に書く")
    parser.add_argument("--no-color", action="store_true", help="写真から色を取らない")
    return parser.parse_args(argv)


def main(argv: list[str]) -> int:
    # Windows の端末(cp932)で出せない字があっても止まらない
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(errors="replace")
    args = parse_args(argv)
    folder = Path(args.dir).expanduser().resolve()
    if not (folder / "frames.json").is_file():
        print(f"frames.json がありません: {folder}(ar-blur-faces.ps1 で展開したフォルダを渡してください)")
        return 2
    depth_zip = Path(args.depth_zip).expanduser().resolve() if args.depth_zip else None
    if depth_zip is None and not (folder / "depth").is_dir():
        print("depth フォルダがありません。消してしまったときは --depth-zip <落とした zip> で zip から読めます")
        return 2
    out_dir = folder / "pointcloud"
    out_dir.mkdir(exist_ok=True)
    print(f"深さから点を作っています: {folder.name}")
    result = build_cloud(
        folder, depth_zip, max_range=args.max_range, voxel=args.voxel, min_hits=args.min_hits,
        with_color=not args.no_color, preview_dir=(folder / "depth_preview") if args.preview else None,
    )
    ply = Path(args.ply).expanduser().resolve() if args.ply else out_dir / "points.ply"
    write_ply(ply, result.points, result.colors)
    image, extent = top_view(result.points, result.floor, result.ceiling)
    top_path = out_dir / "top_view.png"
    top_path.write_bytes(encode_png8(image))
    lines = summary_lines(result, folder) + [f"PLY: {ply}", f"真上の画像: {top_path}(1 px = 5 cm・北が上)"]
    (out_dir / "summary.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print("\n".join(lines))
    if bpy is not None:
        build_blender_scene(result, folder.name, top_path, extent)
        if args.out:
            bpy.ops.wm.save_as_mainfile(filepath=str(Path(args.out).expanduser().resolve()))
            print(f"保存しました: {args.out}")
    return 0


def _argv_after_double_dash() -> list[str]:
    # Blender は自分の引数のあとの「--」から先を台本に渡す
    return sys.argv[sys.argv.index("--") + 1:] if "--" in sys.argv else sys.argv[1:]


if __name__ == "__main__":
    code = main(_argv_after_double_dash())
    if bpy is None or bpy.app.background:
        sys.exit(code)
