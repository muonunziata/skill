"""Optional narration for the video (Gemini TTS or OpenAI TTS) and WAV helpers. Everything works without a voice."""
from __future__ import annotations

import io
import logging
import wave

log = logging.getLogger("lehigh.social")

GEMINI_TTS_MODEL = "gemini-2.5-flash-preview-tts"
OPENAI_TTS_MODEL = "gpt-4o-mini-tts"


class VoiceError(Exception):
    pass


def pcm_to_wav(pcm: bytes, rate: int = 24000, channels: int = 1, width: int = 2) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(channels)
        w.setsampwidth(width)
        w.setframerate(rate)
        w.writeframes(pcm)
    return buf.getvalue()


def wav_info(data: bytes) -> tuple[tuple[int, int, int], float]:
    """((channels, width, rate), seconds)"""
    try:
        with wave.open(io.BytesIO(data), "rb") as w:
            return (w.getnchannels(), w.getsampwidth(), w.getframerate()), w.getnframes() / float(w.getframerate())
    except (wave.Error, EOFError) as exc:
        raise VoiceError(f"audio no válido: {exc}") from exc


def build_track(segments: list[bytes | None], durations: list[float]) -> bytes:
    """One WAV in which segment i starts exactly at sum(durations[:i]); gaps are silence (keeps audio and video in sync)."""
    fmt = None
    for seg in segments:
        if seg:
            fmt = wav_info(seg)[0]
            break
    if fmt is None:
        raise VoiceError("no hay audio que mezclar")
    ch, width, rate = fmt
    frame = ch * width
    out = bytearray()
    for seg, dur in zip(segments, durations):
        target = int(round(dur * rate)) * frame
        pcm = b""
        if seg:
            with wave.open(io.BytesIO(seg), "rb") as w:
                if (w.getnchannels(), w.getsampwidth(), w.getframerate()) != fmt:
                    raise VoiceError("los fragmentos de voz tienen formatos distintos")
                pcm = w.readframes(w.getnframes())
        out += pcm[:target].ljust(target, b"\x00")
    return pcm_to_wav(bytes(out), rate, ch, width)


class Narrator:
    def __init__(self, settings, http=None):
        self.s = settings
        self.provider = settings.social_voice
        self.http = http
        self._gemini = None

    @property
    def enabled(self) -> bool:
        if self.provider == "gemini":
            return bool(self.s.gemini_api_key)
        if self.provider == "openai":
            return bool(self.s.openai_api_key)
        return False

    @property
    def model(self) -> str:
        return getattr(self, "_tts_model", "") or self.s.social_voice_model or (GEMINI_TTS_MODEL if self.provider == "gemini" else OPENAI_TTS_MODEL)

    def speak(self, text: str) -> bytes:
        text = text.strip()
        if not text:
            raise VoiceError("texto vacío")
        try:
            return self._gemini_tts(text) if self.provider == "gemini" else self._openai_tts(text)
        except VoiceError:
            raise
        except Exception as exc:  # noqa: BLE001 - provider SDK errors differ; callers only need "voice failed"
            raise VoiceError(f"{self.provider}: {str(exc)[:200]}") from exc

    def _gemini_tts(self, text: str) -> bytes:
        from google import genai
        from google.genai import types

        client = self._gemini or genai.Client(api_key=self.s.gemini_api_key)
        self._gemini = client
        cfg = types.GenerateContentConfig(
            response_modalities=["AUDIO"],
            speech_config=types.SpeechConfig(voice_config=types.VoiceConfig(
                prebuilt_voice_config=types.PrebuiltVoiceConfig(voice_name="Kore"))))
        try:
            resp = client.models.generate_content(model=self.model, contents=text, config=cfg)
        except Exception as exc:  # noqa: BLE001
            if getattr(exc, "code", 0) != 404:
                raise
            # the TTS model name was retired: use the newest Gemini TTS model this key can see
            names = sorted((getattr(m, "name", "").removeprefix("models/") for m in client.models.list()), reverse=True)
            tts = [n for n in names if n.startswith("gemini-") and "tts" in n and n != self.model]
            if not tts:
                raise
            log.warning("Gemini TTS model '%s' is not served any more; using '%s'", self.model, tts[0])
            self._tts_model = tts[0]
            resp = client.models.generate_content(model=tts[0], contents=text, config=cfg)
        for cand in resp.candidates or []:
            for part in (cand.content.parts if cand.content else None) or []:
                blob = getattr(part, "inline_data", None)
                if blob and blob.data:
                    return pcm_to_wav(blob.data, 24000)  # Gemini returns raw 24 kHz 16-bit mono PCM
        raise VoiceError("Gemini no devolvió audio")

    def _openai_tts(self, text: str) -> bytes:
        import openai

        client = openai.OpenAI(api_key=self.s.openai_api_key)
        resp = client.audio.speech.create(model=self.model, voice="alloy", input=text, response_format="wav")
        data = resp.read() if hasattr(resp, "read") else bytes(resp.content)
        wav_info(data)
        return data
