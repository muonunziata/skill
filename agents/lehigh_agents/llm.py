"""Model router: one small interface over Gemini (google-genai), Claude (anthropic) and OpenAI.

- Agents 1 and 3 use Gemini; Agent 2 can be any provider, selected by the model name in `.env`.
- Every call returns plain text; `json()` adds tolerant parsing, validation and one automatic repair retry.
"""
from __future__ import annotations

import json as _json
import logging
import re
import time
from dataclasses import dataclass, field
from typing import Any, Callable

from .settings import Settings

log = logging.getLogger("lehigh.llm")


class LLMError(Exception):
    def __init__(self, message: str, retryable: bool = False):
        super().__init__(message)
        self.retryable = retryable


def provider_for(model: str) -> str:
    m = (model or "").lower().strip()
    if m.startswith("claude"):
        return "anthropic"
    if m.startswith(("gpt", "chatgpt", "o1", "o3", "o4")):
        return "openai"
    return "gemini"  # gemini-*, gemma-*, and anything unknown


def extract_json(text: str) -> Any:
    """Parse the first JSON value found in `text` (tolerates ``` fences and surrounding prose)."""
    if not text or not text.strip():
        raise ValueError("empty response")
    cleaned = re.sub(r"^\s*```(?:json)?\s*|\s*```\s*$", "", text.strip(), flags=re.I)
    decoder = _json.JSONDecoder()
    for start in (m.start() for m in re.finditer(r"[\[{]", cleaned)):
        try:
            value, _ = decoder.raw_decode(cleaned[start:])
            return value
        except _json.JSONDecodeError:
            continue
    raise ValueError("no valid JSON found in the response")


@dataclass
class Grounded:
    text: str
    queries: list[str] = field(default_factory=list)
    sources: list[dict[str, str]] = field(default_factory=list)  # {uri, title, domain}


class LLM:
    def __init__(self, settings: Settings, sleep: Callable[[float], None] = time.sleep):
        self.s = settings
        self._clients: dict[str, Any] = {}
        self._sleep = sleep
        self.usage: dict[str, dict[str, int]] = {}

    # ───────────────────────── public API ─────────────────────────
    def json(self, model: str, system: str, prompt: str, validate: Callable[[Any], str | None] | None = None,
             max_tokens: int = 8192, label: str = "") -> Any:
        """Ask for JSON, validate it, and retry once with the error message if it is malformed."""
        full = prompt + "\n\nResponde ÚNICAMENTE con JSON válido (sin explicaciones ni bloques de código)."
        last_err = ""
        for attempt in range(2):
            text = self._retry(lambda: self._call(model, system, full if not last_err else
                                                  f"{full}\n\nTu respuesta anterior no fue válida: {last_err}. "
                                                  "Devuelve solo el JSON corregido.", max_tokens, want_json=True))
            try:
                value = extract_json(text)
            except ValueError as exc:
                last_err = str(exc)
                continue
            problem = validate(value) if validate else None
            if problem is None:
                return value
            last_err = problem
        raise LLMError(f"{label or model}: invalid structured output after retry ({last_err})")

    def grounded_search(self, model: str, system: str, prompt: str) -> Grounded:
        if provider_for(model) != "gemini":
            raise LLMError("Google Search grounding requires a Gemini model")
        return self._retry(lambda: self._gemini_grounded(model, system, prompt))

    def available_models(self) -> dict[str, set[str]]:
        """Best-effort listing used by the `check` command (only providers with a key)."""
        out: dict[str, set[str]] = {}
        if self.s.gemini_api_key:
            try:
                out["gemini"] = {m.name.removeprefix("models/") for m in self._client("gemini").models.list()}
            except Exception as exc:  # noqa: BLE001 - diagnostics only
                log.warning("could not list Gemini models: %s", exc)
        return out

    # ───────────────────────── plumbing ─────────────────────────
    def _retry(self, fn: Callable[[], Any], tries: int = 3) -> Any:
        for attempt in range(tries):
            try:
                return fn()
            except LLMError as exc:
                if not exc.retryable or attempt == tries - 1:
                    raise
                delay = 2 ** (attempt + 1)
                log.warning("transient model error (%s); retrying in %ss", exc, delay)
                self._sleep(delay)
        raise AssertionError("unreachable")

    def _client(self, provider: str):
        if provider in self._clients:
            return self._clients[provider]
        if provider == "gemini":
            from google import genai

            c = genai.Client(api_key=self.s.gemini_api_key)
        elif provider == "anthropic":
            import anthropic

            c = anthropic.Anthropic(api_key=self.s.anthropic_api_key)
        else:
            import openai

            c = openai.OpenAI(api_key=self.s.openai_api_key)
        self._clients[provider] = c
        return c

    def _track(self, model: str, inp: int | None, out: int | None) -> None:
        u = self.usage.setdefault(model, {"input": 0, "output": 0, "calls": 0})
        u["input"] += inp or 0
        u["output"] += out or 0
        u["calls"] += 1

    def _call(self, model: str, system: str, prompt: str, max_tokens: int, want_json: bool = True) -> str:
        provider = provider_for(model)
        if provider == "gemini":
            return self._gemini(model, system, prompt, max_tokens, want_json)
        if provider == "anthropic":
            return self._anthropic(model, system, prompt, max_tokens)
        return self._openai(model, system, prompt, max_tokens, want_json)

    # ───────────────────────── Gemini ─────────────────────────
    def _gemini_error(self, exc: Exception, model: str) -> LLMError:
        code = getattr(exc, "code", 0) or 0
        msg = getattr(exc, "message", None) or str(exc)
        if code == 404:
            return LLMError(f"Gemini model '{model}' was not found or is no longer served by the API. "
                            "Set a current model in .env (e.g. gemini-2.5-flash).")
        if code in (401, 403):
            return LLMError(f"Gemini rejected the API key / permissions ({code}): {msg}")
        return LLMError(f"Gemini API error {code}: {msg}", retryable=code == 429 or code >= 500 or code == 0)

    def _gemini(self, model: str, system: str, prompt: str, max_tokens: int, want_json: bool) -> str:
        from google.genai import errors, types

        cfg = types.GenerateContentConfig(
            system_instruction=system,
            max_output_tokens=max_tokens,
            temperature=0.3,
            response_mime_type="application/json" if want_json else None,
        )
        try:
            resp = self._client("gemini").models.generate_content(model=model, contents=prompt, config=cfg)
        except errors.APIError as exc:
            raise self._gemini_error(exc, model) from exc
        except Exception as exc:  # network / SDK problems
            raise LLMError(f"Gemini request failed: {exc}", retryable=True) from exc
        meta = getattr(resp, "usage_metadata", None)
        self._track(model, getattr(meta, "prompt_token_count", 0), getattr(meta, "candidates_token_count", 0))
        text = getattr(resp, "text", None)
        if not text:
            raise LLMError("Gemini returned an empty response (it may have been blocked by a safety filter)")
        return text

    def _gemini_grounded(self, model: str, system: str, prompt: str) -> Grounded:
        from google.genai import errors, types

        if model.lower().startswith("gemini-1."):  # legacy tool name for the 1.x family
            tool = types.Tool(google_search_retrieval=types.GoogleSearchRetrieval())
        else:
            tool = types.Tool(google_search=types.GoogleSearch())
        cfg = types.GenerateContentConfig(system_instruction=system, tools=[tool], temperature=0.2,
                                          max_output_tokens=8192)
        try:
            resp = self._client("gemini").models.generate_content(model=model, contents=prompt, config=cfg)
        except errors.APIError as exc:
            raise self._gemini_error(exc, model) from exc
        except Exception as exc:
            raise LLMError(f"Gemini request failed: {exc}", retryable=True) from exc
        meta = getattr(resp, "usage_metadata", None)
        self._track(model, getattr(meta, "prompt_token_count", 0), getattr(meta, "candidates_token_count", 0))
        cand = (getattr(resp, "candidates", None) or [None])[0]
        gm = getattr(cand, "grounding_metadata", None)
        sources: list[dict[str, str]] = []
        for ch in (getattr(gm, "grounding_chunks", None) or []):
            web = getattr(ch, "web", None)
            if web and getattr(web, "uri", None):
                sources.append({"uri": web.uri, "title": getattr(web, "title", "") or "",
                                "domain": getattr(web, "domain", "") or getattr(web, "title", "") or ""})
        return Grounded(text=getattr(resp, "text", "") or "", queries=list(getattr(gm, "web_search_queries", None) or []),
                        sources=sources)

    # ───────────────────────── Anthropic ─────────────────────────
    def _anthropic(self, model: str, system: str, prompt: str, max_tokens: int) -> str:
        import anthropic

        try:
            r = self._client("anthropic").messages.create(
                model=model, max_tokens=max(max_tokens, 4096), system=system,
                messages=[{"role": "user", "content": prompt}],
            )
        except anthropic.APIStatusError as exc:
            raise LLMError(f"Anthropic API error {exc.status_code}: {exc.message}",
                           retryable=exc.status_code == 429 or exc.status_code >= 500) from exc
        except anthropic.APIConnectionError as exc:
            raise LLMError(f"Anthropic connection error: {exc}", retryable=True) from exc
        self._track(model, r.usage.input_tokens, r.usage.output_tokens)
        if r.stop_reason == "refusal":
            raise LLMError("Claude declined the request (safety refusal)")
        text = "".join(b.text for b in r.content if getattr(b, "type", "") == "text")
        if not text:
            raise LLMError("Claude returned no text")
        return text

    # ───────────────────────── OpenAI ─────────────────────────
    def _openai(self, model: str, system: str, prompt: str, max_tokens: int, want_json: bool) -> str:
        import openai

        kwargs: dict[str, Any] = {"model": model,
                                  "messages": [{"role": "system", "content": system},
                                               {"role": "user", "content": prompt}]}
        if want_json:
            kwargs["response_format"] = {"type": "json_object"}
        try:
            r = self._client("openai").chat.completions.create(**kwargs)
        except openai.APIStatusError as exc:
            raise LLMError(f"OpenAI API error {exc.status_code}: {exc.message}",
                           retryable=exc.status_code == 429 or exc.status_code >= 500) from exc
        except openai.APIConnectionError as exc:
            raise LLMError(f"OpenAI connection error: {exc}", retryable=True) from exc
        if r.usage:
            self._track(model, r.usage.prompt_tokens, r.usage.completion_tokens)
        text = r.choices[0].message.content or ""
        if not text:
            raise LLMError("OpenAI returned an empty response")
        return text
