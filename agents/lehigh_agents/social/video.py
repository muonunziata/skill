"""Vertical 9:16 video from the scene frames: slow zoom (Ken Burns) per scene, cross-fades and optional narration, via ffmpeg."""
from __future__ import annotations

import os
import shutil
import subprocess
import tempfile
from pathlib import Path

FPS = 30
FADE = 0.4
W, H = 1080, 1920


class VideoError(Exception):
    pass


def ffmpeg_path() -> str:
    env = os.environ.get("FFMPEG_PATH", "")
    if env and Path(env).is_file():
        return env
    try:
        import imageio_ffmpeg

        return imageio_ffmpeg.get_ffmpeg_exe()
    except Exception:  # noqa: BLE001 - fall back to a system ffmpeg
        found = shutil.which("ffmpeg")
        if found:
            return found
    raise VideoError("no se encontró ffmpeg (pip install imageio-ffmpeg, o instala ffmpeg y/o define FFMPEG_PATH)")


def _run(cmd: list[str], timeout: int = 300) -> None:
    try:
        p = subprocess.run(cmd, capture_output=True, timeout=timeout)
    except subprocess.TimeoutExpired as exc:
        raise VideoError("ffmpeg tardó demasiado") from exc
    if p.returncode != 0:
        raise VideoError("ffmpeg falló: " + p.stderr.decode("utf-8", "replace").strip().splitlines()[-1][:300])


def scene_starts(durations: list[float]) -> list[float]:
    out, t = [], 0.0
    for d in durations:
        out.append(round(t, 3))
        t += d
    return out


def build_video(frames: list[bytes], durations: list[float], audio_wav: bytes | None = None) -> bytes:
    """frames[i] is a PNG shown for durations[i] seconds. Returns an H.264/AAC MP4 (yuv420p, faststart)."""
    if not frames or len(frames) != len(durations):
        raise VideoError("frames y duraciones no coinciden")
    ff = ffmpeg_path()
    n = len(frames)
    with tempfile.TemporaryDirectory(prefix="lehigh-video-") as tmp:
        tmpd = Path(tmp)
        clips = []
        for i, (png, d) in enumerate(zip(frames, durations)):
            length = d + (FADE if i < n - 1 else 0)
            img, clip = tmpd / f"f{i}.png", tmpd / f"c{i}.mp4"
            img.write_bytes(png)
            frames_n = max(2, int(round(length * FPS)))
            zoom_dir = "min(zoom+0.0006,1.06)" if i % 2 == 0 else "if(eq(on,0),1.06,max(zoom-0.0006,1.0))"
            vf = (f"scale={int(W * 1.2)}:{int(H * 1.2)},zoompan=z='{zoom_dir}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'"
                  f":d={frames_n}:s={W}x{H}:fps={FPS},format=yuv420p")
            _run([ff, "-y", "-loglevel", "error", "-loop", "1", "-i", str(img), "-vf", vf, "-frames:v", str(frames_n),
                  "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", "-pix_fmt", "yuv420p", str(clip)])
            clips.append(clip)

        starts = scene_starts(durations)
        cmd = [ff, "-y", "-loglevel", "error"]
        for c in clips:
            cmd += ["-i", str(c)]
        audio_idx = None
        if audio_wav:
            wav = tmpd / "voice.wav"
            wav.write_bytes(audio_wav)
            cmd += ["-i", str(wav)]
            audio_idx = n
        if n == 1:
            graph, last = "[0:v]null[v]", "[v]"
        else:
            parts, prev = [], "[0:v]"
            for i in range(1, n):
                out = f"[x{i}]" if i < n - 1 else "[v]"
                parts.append(f"{prev}[{i}:v]xfade=transition=fade:duration={FADE}:offset={starts[i]}{out}")
                prev = out
            graph, last = ";".join(parts), "[v]"
        out_file = tmpd / "out.mp4"
        cmd += ["-filter_complex", graph, "-map", last]
        if audio_idx is not None:
            cmd += ["-map", f"{audio_idx}:a", "-af", "apad", "-c:a", "aac", "-b:a", "128k"]
        cmd += ["-t", f"{sum(durations):.2f}", "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
                "-r", str(FPS), "-movflags", "+faststart", str(out_file)]
        _run(cmd, timeout=600)
        return out_file.read_bytes()
