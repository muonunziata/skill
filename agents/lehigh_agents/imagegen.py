"""Photorealistic image generation through a pluggable provider (Replicate/Flux, Gemini "Nano Banana", OpenAI-compatible).

`ImageGenerator.generate(prompt)` returns the image bytes (always) and the provider's remote URL when it has one.
Remote URLs from providers expire, so callers are expected to host the bytes (the pipeline uploads them to the
WordPress media library and uses that permanent URL as `featured_image_url`).
"""
from __future__ import annotations

import base64
import logging
import time
from dataclasses import dataclass
from typing import Any

import requests

from .media import image_size
from .net import FetchError, Fetcher
from .settings import Settings

log = logging.getLogger("lehigh.imagegen")

DEFAULT_MODELS = {
    "replicate": "black-forest-labs/flux-1.1-pro",
    "gemini": "gemini-2.5-flash-image",
    "openai": "gpt-image-1",
}
REPLICATE_ENDPOINT = "https://api.replicate.com/v1/models/{model}/predictions"
OPENAI_ENDPOINT = "https://api.openai.com/v1/images/generations"
MAX_IMAGE_BYTES = 15_000_000
_EXT = {"image/jpeg": "jpg", "image/png": "png", "image/webp": "webp"}


class ImageGenError(Exception):
    pass


@dataclass
class GeneratedImage:
    data: bytes
    mime: str
    provider: str
    model: str
    remote_url: str = ""
    width: int = 0
    height: int = 0
    seconds: float = 0.0

    @property
    def extension(self) -> str:
        return _EXT.get(self.mime, "jpg")


def sniff_mime(data: bytes) -> str:
    if data[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "image/png"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    return ""


class ImageGenerator:
    def __init__(self, settings: Settings, fetcher: Fetcher | None = None, http: Any = None, sleep=time.sleep):
        self.s = settings
        self.provider = settings.image_provider
        self.model = settings.image_model or DEFAULT_MODELS.get(self.provider, "")
        self.fetcher = fetcher or Fetcher(timeout=60, max_bytes=MAX_IMAGE_BYTES)
        self.http = http or requests
        self._sleep = sleep

    @property
    def enabled(self) -> bool:
        return bool(self.provider)

    @property
    def api_key(self) -> str:
        return self.s.image_api_key or (self.s.gemini_api_key if self.provider == "gemini" else "")

    # ───────────────────────── public ─────────────────────────
    def generate(self, prompt: str, aspect_ratio: str | None = None) -> GeneratedImage:
        if not self.enabled:
            raise ImageGenError("image generation is not configured (set IMAGE_API_KEY)")
        if len(prompt.strip()) < 20:
            raise ImageGenError("image prompt is too short")
        aspect = aspect_ratio or self.s.image_aspect_ratio
        started = time.time()
        try:
            if self.provider == "replicate":
                img = self._replicate(prompt, aspect)
            elif self.provider == "gemini":
                img = self._gemini(prompt, aspect)
            elif self.provider == "openai":
                img = self._openai(prompt, aspect)
            else:
                raise ImageGenError(f"unsupported IMAGE_PROVIDER '{self.provider}'")
        except requests.RequestException as exc:
            raise ImageGenError(f"{self.provider} request failed: {exc}") from exc
        img.seconds = round(time.time() - started, 1)
        return self._verify(img)

    # ───────────────────────── providers ─────────────────────────
    def _replicate(self, prompt: str, aspect: str) -> GeneratedImage:
        endpoint = self.s.image_api_endpoint or REPLICATE_ENDPOINT
        endpoint = endpoint.replace("{model}", self.model)
        body_input = {"prompt": prompt, "aspect_ratio": aspect, "output_format": "jpg", **self.s.image_extra}
        headers = {"Authorization": f"Bearer {self.api_key}", "Content-Type": "application/json",
                   "Prefer": "wait=60"}
        r = self.http.post(endpoint, headers=headers, json={"input": body_input}, timeout=90)
        if r.status_code >= 400:
            raise ImageGenError(f"Replicate HTTP {r.status_code}: {r.text[:300]}")
        pred = r.json()
        deadline = time.time() + 180
        while pred.get("status") in ("starting", "processing"):
            if time.time() > deadline:
                raise ImageGenError("Replicate prediction timed out")
            url = (pred.get("urls") or {}).get("get")
            if not url:
                raise ImageGenError("Replicate response has no polling URL")
            self._sleep(2)
            pr = self.http.get(url, headers={"Authorization": f"Bearer {self.api_key}"}, timeout=30)
            if pr.status_code >= 400:
                raise ImageGenError(f"Replicate poll HTTP {pr.status_code}: {pr.text[:200]}")
            pred = pr.json()
        if pred.get("status") != "succeeded":
            raise ImageGenError(f"Replicate prediction {pred.get('status')}: {pred.get('error') or 'no detail'}")
        out = pred.get("output")
        url = out[0] if isinstance(out, list) and out else out
        if not isinstance(url, str) or not url.startswith("http"):
            raise ImageGenError("Replicate returned no image URL")
        return GeneratedImage(data=self._download(url), mime="", provider="replicate", model=self.model, remote_url=url)

    def _gemini(self, prompt: str, aspect: str) -> GeneratedImage:
        from google import genai
        from google.genai import errors, types

        http_options = types.HttpOptions(base_url=self.s.image_api_endpoint) if self.s.image_api_endpoint else None
        client = genai.Client(api_key=self.api_key, http_options=http_options)
        image_cfg: dict[str, Any] = {"aspect_ratio": aspect}
        cfg = types.GenerateContentConfig(response_modalities=["IMAGE"], image_config=types.ImageConfig(**image_cfg))
        try:
            resp = client.models.generate_content(model=self.model, contents=prompt, config=cfg)
        except errors.APIError as exc:
            raise ImageGenError(f"Gemini image API error {exc.code}: {exc.message}") from exc
        for cand in resp.candidates or []:
            for part in (cand.content.parts if cand.content else None) or []:
                blob = getattr(part, "inline_data", None)
                if blob and blob.data:
                    return GeneratedImage(data=blob.data, mime=blob.mime_type or "", provider="gemini", model=self.model)
        raise ImageGenError("Gemini returned no image (the prompt may have been blocked by safety filters)")

    def _openai(self, prompt: str, aspect: str) -> GeneratedImage:
        endpoint = self.s.image_api_endpoint or OPENAI_ENDPOINT
        body: dict[str, Any] = {"model": self.model, "prompt": prompt, "n": 1}
        if self.model.startswith("gpt-image"):
            body["size"] = "1536x1024" if aspect in ("16:9", "3:2") else "1024x1024"
        elif self.model.startswith("dall-e-3"):
            body["size"] = "1792x1024" if aspect in ("16:9", "3:2") else "1024x1024"
            body["response_format"] = "b64_json"
        body.update(self.s.image_extra)
        r = self.http.post(endpoint, headers={"Authorization": f"Bearer {self.api_key}", "Content-Type": "application/json"},
                           json=body, timeout=180)
        if r.status_code >= 400:
            raise ImageGenError(f"image API HTTP {r.status_code}: {r.text[:300]}")
        items = (r.json() or {}).get("data") or []
        if not items:
            raise ImageGenError("image API returned no data")
        first = items[0]
        if first.get("b64_json"):
            return GeneratedImage(data=base64.b64decode(first["b64_json"]), mime="", provider="openai", model=self.model)
        if first.get("url"):
            return GeneratedImage(data=self._download(first["url"]), mime="", provider="openai", model=self.model,
                                  remote_url=first["url"])
        raise ImageGenError("image API response has neither b64_json nor url")

    # ───────────────────────── helpers ─────────────────────────
    def _download(self, url: str) -> bytes:
        try:
            res = self.fetcher.get(url, max_bytes=MAX_IMAGE_BYTES, accept="image/*")
        except FetchError as exc:
            raise ImageGenError(f"could not download the generated image: {exc}") from exc
        if res.truncated:
            raise ImageGenError("generated image is larger than the allowed size")
        return res.body

    def _verify(self, img: GeneratedImage) -> GeneratedImage:
        mime = sniff_mime(img.data)
        if not mime:
            raise ImageGenError("provider returned data that is not a JPEG, PNG or WebP image")
        if len(img.data) > MAX_IMAGE_BYTES:
            raise ImageGenError("generated image is too large")
        img.mime = mime
        img.width, img.height = image_size(img.data)
        if img.width and img.width < 512:
            raise ImageGenError(f"generated image is too small ({img.width}px wide)")
        return img
