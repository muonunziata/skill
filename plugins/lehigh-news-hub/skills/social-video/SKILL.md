---
name: social-video
description: Script and build the vertical 9:16 video (Reels / TikTok / Shorts) for a GoLehighAcres.org article - scenes, narration, optional AI voice, ffmpeg assembly. Use when asked to write a video script, add narration, change timing or transitions, or debug video output.
---

# Vertical video (1080x1920, H.264/AAC)

Apply `brand-guide`. Code: `social/video.py` (ffmpeg), `social/voice.py` (TTS + WAV helpers), scene frames from `templates.scene_html`.

## Script contract
- 3-9 scenes (prompt asks for 4-7, ~`SOCIAL_VIDEO_SECONDS` total, default 30). Each scene: `narration` (spoken, <= 160 chars, short sentences, first scene is the hook) and `text` (on-screen, <= 60 chars).
- A brand outro (vertical logo + "Síguenos") is appended automatically (2.6 s).
- Same fact rules as carousels: only figures from the article, attribute the source.

## How it is built
Each scene is a PNG frame (Chromium) -> slow Ken Burns zoom clip (`zoompan`) -> cross-fades (`xfade`, 0.4 s) -> one MP4 with `+faststart`. Scene length = narration length (+0.5 s) clamped to 2.4-14 s; without a voice it is estimated at ~2.6 words/second.

## Voice (optional)
`SOCIAL_VOICE=none|gemini|openai`. Gemini TTS (`gemini-2.5-flash-preview-tts`, voice "Kore") returns 24 kHz PCM; OpenAI uses `gpt-4o-mini-tts` WAV. All segments must share a format (`voice.build_track` pads each to its scene so audio and video stay in sync). If the voice fails the video is still produced without sound and the kit carries a warning.

## Checks
```bash
cd agents && python main.py social --post <id> --dry-run --out /tmp/kit
ffmpeg -i /tmp/kit/*/video.mp4          # expect h264, 1080x1920, aac (when voiced)
python -m pytest tests/test_social.py -q -k video
```
ffmpeg comes from `imageio-ffmpeg`; override with `FFMPEG_PATH`.
