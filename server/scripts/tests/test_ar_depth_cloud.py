"""
scripts/ar-depth-cloud.py の試験(numpy だけ。Blender は要らない)。

  python -I -m unittest discover -s server/scripts/tests
"""

import importlib.util
import json
import math
import struct
import sys
import tempfile
import unittest
import zipfile
import zlib
from pathlib import Path

import numpy as np

_SCRIPT = Path(__file__).resolve().parents[1] / "ar-depth-cloud.py"
_spec = importlib.util.spec_from_file_location("ar_depth_cloud", _SCRIPT)
cloud = importlib.util.module_from_spec(_spec)
sys.modules["ar_depth_cloud"] = cloud   # dataclass が自分の型をここから探す
_spec.loader.exec_module(cloud)


def png16(depth_mm: np.ndarray, filters=(0,)) -> bytes:
    """アプリの encodeGray16Png と同じ形の PNG。[filters] を行ごとに順に使う(読む側が全部の型を戻せるかを見る)。"""
    h, w = depth_mm.shape
    rows = depth_mm.astype(">u2").view(np.uint8).reshape(h, w * 2).astype(np.int32)
    raw = bytearray()
    prev = np.zeros(w * 2, dtype=np.int32)
    for r in range(h):
        kind = filters[r % len(filters)]
        cur = rows[r]
        out = np.empty_like(cur)
        for x in range(len(cur)):
            a = int(cur[x - 2]) if x >= 2 else 0
            b = int(prev[x])
            c = int(prev[x - 2]) if x >= 2 else 0
            pred = {0: 0, 1: a, 2: b, 3: (a + b) // 2}.get(kind)
            if kind == 4:
                p = a + b - c
                pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                pred = a if pa <= pb and pa <= pc else (b if pb <= pc else c)
            out[x] = (int(cur[x]) - pred) & 255
        raw.append(kind)
        raw += bytes(out.astype(np.uint8))
        prev = cur

    def chunk(kind, body):
        return struct.pack(">I", len(body)) + kind + body + struct.pack(">I", zlib.crc32(kind + body) & 0xFFFFFFFF)

    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 16, 0, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(bytes(raw))) + chunk(b"IEND", b""))


def frame(name="frame_0000.jpg", t_ms=1_791_500_000_000, t=(0.0, 0.0, 0.0), q=(0.0, 0.0, 0.0, 1.0), depth="frame_0000.png"):
    return {
        "name": name, "depth": depth, "timestampMillis": t_ms,
        "pose": {"t": list(t), "q": list(q)},
        "intrinsics": {"fx": 1440.0, "fy": 1440.0, "cx": 960.0, "cy": 540.0, "width": 1920, "height": 1080},
    }


class PngTest(unittest.TestCase):
    def test_reads_every_row_filter(self):
        depth = (np.arange(16 * 9, dtype=np.uint32).reshape(9, 16) * 397 % 65000).astype(np.uint16)
        for filters in ((0,), (1,), (2,), (3,), (4,), (0, 1, 2, 3, 4)):
            np.testing.assert_array_equal(cloud.read_png16(png16(depth, filters)), depth, err_msg=str(filters))

    def test_rejects_other_pngs(self):
        rgb = cloud.encode_png8(np.zeros((2, 2, 3), dtype=np.uint8))
        with self.assertRaises(ValueError):
            cloud.read_png16(rgb)


class GeometryTest(unittest.TestCase):
    def test_wall_three_metres_in_front(self):
        f = cloud.load_frames([frame()])[0]
        depth = np.full((90, 160), 3000, dtype=np.uint16)
        world, pixels = cloud.backproject(depth, f)
        self.assertEqual(len(world), 160 * 90)
        np.testing.assert_allclose(world[:, 2], -3.0)   # カメラの前(-z)に 3 m
        # 写真の真ん中の画素は真正面
        center = np.argmin(np.hypot(pixels[:, 0] - 960, pixels[:, 1] - 540))
        self.assertLess(abs(world[center, 0]) + abs(world[center, 1]), 0.05)
        # 画像の上(v が小さい)は世界の上(+y)
        top = world[np.argmin(pixels[:, 1])]
        self.assertGreater(top[1], 0)

    def test_turned_camera(self):
        # y 軸のまわりに +90°: カメラの前(-z)は世界の -x
        s = math.sin(math.pi / 4)
        f = cloud.load_frames([frame(t=(1.0, 1.5, 2.0), q=(0.0, s, 0.0, s))])[0]
        world, _ = cloud.backproject(np.full((90, 160), 2000, dtype=np.uint16), f)
        np.testing.assert_allclose(world[:, 0], 1.0 - 2.0, atol=1e-9)

    def test_drops_far_near_and_edge_pixels(self):
        f = cloud.load_frames([frame()])[0]
        depth = np.full((90, 160), 2000, dtype=np.uint16)
        depth[:, 80:] = 4000          # 段差(物の縁)
        depth[0, 0] = 100             # 近すぎる
        depth[89, 159] = 9000         # 遠すぎる
        world, pixels = cloud.backproject(depth, f, max_range=5.0)
        cols = (pixels[:, 0] * 160 / 1920).astype(int)
        self.assertFalse(np.any((cols == 79) | (cols == 80)), "縁の両側の画素は外す")
        # 段差の両側 2 列(180)。近すぎる・遠すぎる画素は隣との差も大きいので、それぞれ隣 2 つも縁として外れる(3 + 3)
        self.assertEqual(len(world), 90 * 160 - 2 * 90 - 3 - 3)


class PlacementTest(unittest.TestCase):
    def test_same_formula_as_realityscan_prep(self):
        survey = {"alignment": {"originArY": 1.2, "segments": [
            {"fromT": 0, "toT": 10_000, "rotationDegrees": 30.0, "east0": 2.0, "north0": -1.0}]}}
        frames = cloud.load_frames([frame(t_ms=5_000)])
        p = cloud.make_placement(survey, frames)
        enu = p.to_enu(5_000, np.array([[1.0, 1.5, -2.0]]))[0]
        r = math.radians(30)
        a, b = 1.0, 2.0   # a = x・b = -z
        self.assertAlmostEqual(enu[0], math.cos(r) * a - math.sin(r) * b + 2.0)
        self.assertAlmostEqual(enu[1], math.sin(r) * a + math.cos(r) * b - 1.0)
        self.assertAlmostEqual(enu[2], 0.3)
        self.assertIsNone(p.to_enu(20_000, np.zeros((1, 3))), "区間の外は使わない")

    def test_without_alignment_relative_to_first_camera(self):
        frames = cloud.load_frames([frame(t=(1.0, 1.4, 3.0))])
        p = cloud.make_placement({}, frames)
        self.assertFalse(p.aligned)
        np.testing.assert_allclose(p.to_enu(0, np.array([[2.0, 0.4, 1.0]]))[0], [1.0, 2.0, -1.0])


class ReductionTest(unittest.TestCase):
    def test_voxel_and_min_hits(self):
        pts = np.array([[0.01, 0.01, 0.01], [0.02, 0.02, 0.02], [1.0, 1.0, 1.0]])
        ids = np.array([0, 1, 0])
        out, colors, hits = cloud.voxel_downsample(pts, np.array([[1.0, 0, 0], [0, 0, 1.0], [0, 1.0, 0]]), ids, 0.05, 2)
        np.testing.assert_allclose(out, [[0.015, 0.015, 0.015]])
        np.testing.assert_allclose(colors, [[0.5, 0.0, 0.5]])
        self.assertEqual(list(hits), [2])
        out1, _, _ = cloud.voxel_downsample(pts, None, ids, 0.05, 1)
        self.assertEqual(len(out1), 2)

    def test_floor_and_ceiling(self):
        rng = np.random.default_rng(1)
        floor = np.full(4000, -1.40) + rng.normal(0, 0.01, 4000)
        ceiling = np.full(3000, 1.30) + rng.normal(0, 0.01, 3000)
        walls = rng.uniform(-1.4, 1.3, 6000)
        f, c = cloud.estimate_floor_ceiling(np.concatenate([floor, ceiling, walls]))
        self.assertAlmostEqual(f, -1.4, delta=0.05)
        self.assertAlmostEqual(c, 1.3, delta=0.05)
        self.assertEqual(cloud.estimate_floor_ceiling(rng.uniform(-1, 1, 1000)), (None, None))


class PipelineTest(unittest.TestCase):
    def _capture(self, root: Path, *, use_zip: bool):
        same = np.full((90, 160), 2500, dtype=np.uint16)
        other = np.full((90, 160), 3000, dtype=np.uint16)
        empty = np.zeros((90, 160), dtype=np.uint16)
        depths = {"frame_0000.png": same, "frame_0001.png": same, "frame_0002.png": empty, "frame_0003.png": other}
        frames = [frame(f"frame_000{i}.jpg", 1_791_500_000_000 + i * 500, (0, 0, 0.1 * i), depth=f"frame_000{i}.png") for i in range(4)]
        frames.append(frame("frame_0004.jpg", 1_791_500_002_000, depth=None))
        (root / "frames.json").write_text(json.dumps(frames), encoding="utf-8")
        (root / "survey.json").write_text("{}", encoding="utf-8")
        if use_zip:
            z = root / "capture.zip"
            with zipfile.ZipFile(z, "w") as zf:
                for n, d in depths.items():
                    zf.writestr(f"depth/{n}", png16(d))
                zf.writestr("images/frame_0000.jpg", b"not read")
            return z
        (root / "depth").mkdir()
        for n, d in depths.items():
            (root / "depth" / n).write_bytes(png16(d))
        return None

    def test_skips_stale_empty_and_missing_depth(self):
        for use_zip in (False, True):
            with tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                z = self._capture(root, use_zip=use_zip)
                result = cloud.build_cloud(root, z, min_hits=1, with_color=False, preview_dir=root / "depth_preview", log=lambda *_: None)
                s = result.stats
                self.assertEqual((s["used"], s["stale"], s["empty"], s["no_depth"]), (2, 1, 1, 1), f"zip={use_zip}")
                self.assertFalse(result.aligned)
                self.assertGreater(len(result.points), 0)
                self.assertIsNone(result.colors)
                # 目で見る画像は空のものも含めて書く(古いものは書かない)
                self.assertEqual(sorted(p.name for p in (root / "depth_preview").iterdir()), ["frame_0000.png", "frame_0002.png", "frame_0003.png"])
                if use_zip:
                    self.assertFalse((root / "images").exists(), "zip の写真は取り出さない")

    def test_ply_and_top_view(self):
        pts = np.array([[0.0, 0.0, 0.0], [1.0, 2.0, 0.5]])
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "p.ply"
            cloud.write_ply(path, pts, np.array([[1.0, 0.0, 0.0], [0.0, 0.5, 1.0]]))
            data = path.read_bytes()
            head, body = data.split(b"end_header\n", 1)
            self.assertIn(b"element vertex 2", head)
            self.assertIn(b"property uchar red", head)
            self.assertEqual(len(body), 2 * (12 + 3))
            self.assertEqual(struct.unpack("<fffBBB", body[15:30]), (1.0, 2.0, 0.5, 0, 128, 255))
        image, extent = cloud.top_view(pts, None, None, res=0.5)
        self.assertEqual(image.dtype, np.uint8)
        west, south, east, north = extent
        self.assertLess(west, 0)
        self.assertGreater(north, 2)
        # 北が上: 北東の点 (1, 2) は南西の点 (0, 0) より上の行・右の列(白地に黒)
        rows, cols = np.nonzero(image < 255)
        self.assertEqual(len(rows), 2)
        north_east = np.argmin(rows)
        self.assertGreater(cols[north_east], cols[1 - north_east])


if __name__ == "__main__":
    unittest.main()
