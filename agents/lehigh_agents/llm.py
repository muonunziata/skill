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

from .models import AUTO, FALLBACK_ALIAS, pick_text_model, rank_text_models
from .settings import Settings

log = logging.getLogger("lehigh.llm")


class LLMError(Exception):
    def __init__(self, message: str, retryable: bool = False):
        super().__init__(message)
        self.retryable = retryable


def provider_for(model: str) -> str:
    m = (model or "").lower().strip()
    if m.startswith("opencode/"):
        return "opencode"      # one or several OpenCode models ("opencode/a,opencode/b")
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
        self._swapped: dict[str, str] = {}     # requested/retired model name -> model the API really serves
        self._catalog_cache: list | None = None
        self._cool: dict[str, float] = {}      # model -> time until which its quota counts as exhausted
        self._alt: dict[str, str] = {}         # exhausted model -> the model used instead meanwhile
        self._last_call = 0.0

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

    # ───────────────────────── model names that survive Google's retirements ─────────────────────────
    def _catalog(self) -> list:
        """Models the API offers to this key (cached); an unreadable list is just empty."""
        if self._catalog_cache is None:
            try:
                self._catalog_cache = list(self._client("gemini").models.list())
            except Exception as exc:  # noqa: BLE001 - network/auth problems: fall back to the alias
                log.warning("could not list Gemini models: %s", exc)
                return []
        return self._catalog_cache

    def _ranked(self, exclude: set[str] | None = None, include_lite: bool = False) -> list[str]:
        """Candidate models, best first, skipping the excluded ones and those whose quota is exhausted right now."""
        now = time.time()
        names = rank_text_models(self._catalog(), include_lite=include_lite) or [FALLBACK_ALIAS, "gemini-flash-lite-latest"]
        return [n for n in names if n not in (exclude or set()) and self._cool.get(n, 0) <= now]

    def _pick_replacement(self, avoid: str) -> str:
        ranked = self._ranked(exclude={avoid})
        if ranked:
            return ranked[0]
        return FALLBACK_ALIAS if avoid != FALLBACK_ALIAS else ""

    def gemini_model(self, model: str) -> str:
        """The model name to send: `auto` becomes the newest available Flash model; retired names follow their replacement."""
        name = (model or "").strip()
        if name.lower() in AUTO and name not in self._swapped:
            picked = self._pick_replacement(avoid="")
            self._swapped[name] = picked
            log.info("model 'auto' -> %s", picked)
        for _ in range(4):                       # follow replacement chains (auto -> X -> Y)
            if name in self._alt and self._cool.get(name, 0) > time.time():
                name = self._alt[name]          # its quota is exhausted for now: keep using the stand-in
            if name not in self._swapped:
                break
            name = self._swapped[name]
        return name

    def resolved(self, model: str) -> str:
        """Name to show in reports: the model actually in use."""
        return self.gemini_model(model) if provider_for(model) == "gemini" else model

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
        if provider == "opencode":
            return self._opencode(model, system, prompt)
        if provider == "gemini":
            return self._gemini(model, system, prompt, max_tokens, want_json)
        if provider == "anthropic":
            return self._anthropic(model, system, prompt, max_tokens)
        return self._openai(model, system, prompt, max_tokens, want_json)

    # ───────────────────────── OpenCode ─────────────────────────
    def max_prompt_chars(self, model: str) -> int:
        """Longest prompt the engine takes (0 = no practical limit); callers split big inputs into batches."""
        from .opencode import MAX_ARG_CHARS

        return MAX_ARG_CHARS - 4000 if provider_for(model) == "opencode" else 0

    def _opencode(self, model: str, system: str, prompt: str) -> str:
        from .opencode import OpenCodeError, OpenCodeRunner

        runner = self._clients.get("opencode")
        if runner is None:
            runner = self._clients["opencode"] = OpenCodeRunner(getattr(self.s, "opencode_path", ""),
                                                                getattr(self.s, "opencode_timeout", 300))
        try:
            text, used = runner.complete(model, system, prompt)
        except OpenCodeError as exc:
            raise LLMError(f"OpenCode: {exc}", retryable=exc.limit) from exc
        self._track(used, (len(system) + len(prompt)) // 4, len(text) // 4)
        return text

    # ───────────────────────── Gemini ─────────────────────────
    def _gemini_error(self, exc: Exception, model: str) -> LLMError:
        code = getattr(exc, "code", 0) or 0
        msg = getattr(exc, "message", None) or str(exc)
        if code == 404:
            return LLMError(f"Gemini model '{model}' was not found or is no longer served by the API. "
                            "Put REDACCTOR_MODEL / RASTREADOR_MODEL / AUDITOR_MODEL=auto in .env and the agents pick a current model by themselves.")
        if code in (401, 403):
            return LLMError(f"Gemini rejected the API key / permissions ({code}): {msg}")
        return LLMError(f"Gemini API error {code}: {msg}", retryable=code == 429 or code >= 500 or code == 0)

    def _throttle(self) -> None:
        """Stay under the key's requests-per-minute limit (free plans are small) instead of colliding with HTTP 429."""
        rpm = getattr(self.s, "gemini_rpm", 0)
        if rpm > 0:
            wait = self._last_call + 60.0 / rpm - time.time()
            if wait > 0:
                self._sleep(wait)
        self._last_call = time.time()

    @staticmethod
    def _retry_delay(exc: Exception) -> float | None:
        """Seconds Google asks us to wait ("Please retry in 23.4s" / retryDelay: '23s'); None when it does not say."""
        text = f"{getattr(exc, 'message', '')} {exc}"
        m = re.search(r"retry in ([\d.]+)\s*s", text, re.I) or re.search(r"retryDelay['\"]?\s*:\s*['\"]?([\d.]+)s", text)
        return float(m.group(1)) if m else None

    def _gemini_generate(self, model: str, prompt: str, cfg: Any) -> Any:
        """One generate_content call with error mapping and token accounting (shared by plain and grounded requests)."""
        from google.genai import errors

        model = self.gemini_model(model)

        def call(m: str) -> Any:
            self._throttle()
            return self._client("gemini").models.generate_content(model=m, contents=prompt, config=cfg)

        try:
            try:
                resp = call(model)
            except errors.APIError as exc:
                code = getattr(exc, "code", 0)
                if code == 404:
                    # Google retired this name (or the key cannot use it): use the newest model that is served and retry once
                    replacement = self._pick_replacement(avoid=model)
                    if not replacement:
                        raise
                    log.warning("Gemini model '%s' is not served any more; using '%s' instead", model, replacement)
                    self._swapped[model] = replacement
                    model = replacement
                    resp = call(model)
                elif code == 429:
                    resp, model = self._after_quota_error(model, call, exc)
                else:
                    raise
        except errors.APIError as exc:
            raise self._gemini_error(exc, model) from exc
        except LLMError:
            raise
        except Exception as exc:  # network / SDK problems
            raise LLMError(f"Gemini request failed: {exc}", retryable=True) from exc
        meta = getattr(resp, "usage_metadata", None)
        self._track(model, getattr(meta, "prompt_token_count", 0), getattr(meta, "candidates_token_count", 0))
        return resp

    def _after_quota_error(self, model: str, call: Callable[[str], Any], exc: Exception) -> tuple[Any, str]:
        """HTTP 429: wait if Google says it is a short per-minute limit, otherwise move to another model (quotas are per model)."""
        from google.genai import errors

        text = f"{getattr(exc, 'message', '')} {exc}"
        delay = self._retry_delay(exc)
        if delay is not None and delay <= 75 and "limit: 0" not in text and "PerDay" not in text:
            log.warning("Gemini quota hit on %s; waiting %.0fs as Google asks", model, delay)
            self._sleep(delay + 1)
            try:
                return call(model), model
            except errors.APIError as exc2:
                if getattr(exc2, "code", 0) != 429:
                    raise
        self._cool[model] = time.time() + 900
        tried = [model]
        for cand in self._ranked(exclude={model}, include_lite=True)[:3]:
            tried.append(cand)
            try:
                resp = call(cand)
            except errors.APIError as exc3:
                if getattr(exc3, "code", 0) == 429:
                    self._cool[cand] = time.time() + 900
                    continue
                if getattr(exc3, "code", 0) == 404:
                    continue
                raise
            log.warning("Gemini quota exhausted on %s; using %s for now", model, cand)
            self._alt[model] = cand
            return resp, cand
        raise LLMError(
            "Google dice que se agotó la cuota de Gemini de tu clave (error 429) en: " + ", ".join(tried) + ". "
            "Si es el plan gratuito: espera unos minutos (límite por minuto) o hasta mañana (límite diario), o activa la facturación "
            "en https://aistudio.google.com/. Para gastar menos: baja MAX_ITEMS_PER_RUN, desactiva SOCIAL_ENABLED o sube el intervalo de ejecución.",
            retryable=False)

    def _gemini(self, model: str, system: str, prompt: str, max_tokens: int, want_json: bool) -> str:
        from google.genai import types

        cfg = types.GenerateContentConfig(
            system_instruction=system,
            max_output_tokens=max_tokens,
            temperature=0.3,
            response_mime_type="application/json" if want_json else None,
        )
        resp = self._gemini_generate(model, prompt, cfg)
        text = getattr(resp, "text", None)
        if not text:
            raise LLMError("Gemini returned an empty response (it may have been blocked by a safety filter)")
        return text

    def _gemini_grounded(self, model: str, system: str, prompt: str) -> Grounded:
        from google.genai import types

        model = self.gemini_model(model)
        if model.lower().startswith("gemini-1."):  # legacy tool name for the 1.x family
            tool = types.Tool(google_search_retrieval=types.GoogleSearchRetrieval())
        else:
            tool = types.Tool(google_search=types.GoogleSearch())
        cfg = types.GenerateContentConfig(system_instruction=system, tools=[tool], temperature=0.2,
                                          max_output_tokens=8192)
        resp = self._gemini_generate(model, prompt, cfg)
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
