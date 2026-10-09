"""Gemini retires model names every few months: the agents must keep working without anybody editing the .env."""
from types import SimpleNamespace

import pytest
from google.genai import errors

from lehigh_agents.llm import LLM, LLMError
from lehigh_agents.models import FALLBACK_ALIAS


def not_found(name):
    return errors.ClientError(404, {"error": {"message": f"models/{name} is not found", "status": "NOT_FOUND"}}, None)


class Models:
    def __init__(self, served, listing=None):
        self.served, self.listing, self.calls, self.lists = set(served), listing, [], 0

    def generate_content(self, model, contents, config):
        self.calls.append(model)
        if model not in self.served:
            raise not_found(model)
        return SimpleNamespace(text='{"ok": true}', usage_metadata=SimpleNamespace(prompt_token_count=1, candidates_token_count=1))

    def list(self):
        self.lists += 1
        if self.listing is None:
            raise errors.ServerError(500, {"error": {"message": "down"}}, None)
        return [SimpleNamespace(name=f"models/{n}", supported_actions=["generateContent"]) for n in self.listing]


def make(settings, models):
    llm = LLM(settings, sleep=lambda s: None)
    llm._clients["gemini"] = SimpleNamespace(models=models)
    return llm


def test_a_retired_model_is_replaced_by_the_newest_served_flash(settings):
    m = Models(served={"gemini-3-flash"}, listing=["gemini-2.0-flash-lite", "gemini-2.5-flash", "gemini-3-flash", "gemini-3-pro"])
    llm = make(settings, m)
    assert llm.json("gemini-2.5-flash", "sys", "p") == {"ok": True}
    assert m.calls == ["gemini-2.5-flash", "gemini-3-flash"]               # retried once with the replacement
    llm.json("gemini-2.5-flash", "sys", "p")
    assert m.calls[-1] == "gemini-3-flash" and len(m.calls) == 3 and m.lists == 1   # remembered: no second 404, list read once
    assert llm.resolved("gemini-2.5-flash") == "gemini-3-flash"


def test_auto_picks_a_model_up_front_without_a_wasted_call(settings):
    m = Models(served={"gemini-3-flash"}, listing=["gemini-2.5-flash", "gemini-3-flash"])
    llm = make(settings, m)
    assert llm.json("auto", "s", "p") == {"ok": True} and m.calls == ["gemini-3-flash"]
    assert llm.resolved("auto") == "gemini-3-flash" and llm.resolved("") == "gemini-3-flash"
    assert llm.resolved("claude-sonnet-5-5") == "claude-sonnet-5-5"          # other providers are untouched


def test_when_the_list_is_unreadable_the_moving_alias_is_used(settings):
    m = Models(served={FALLBACK_ALIAS}, listing=None)
    llm = make(settings, m)
    assert llm.json("gemini-1.5-flash", "s", "p") == {"ok": True}
    assert m.calls == ["gemini-1.5-flash", FALLBACK_ALIAS]


def test_nothing_works_gives_a_clear_error(settings):
    m = Models(served=set(), listing=["gemini-3-flash"])
    with pytest.raises(LLMError, match="auto"):
        make(settings, m).json("gemini-2.5-flash", "s", "p")


def test_grounded_search_also_follows_the_replacement(settings):
    seen = []

    class GM(Models):
        def generate_content(self, model, contents, config):
            seen.append(model)
            r = super().generate_content(model, contents, config)
            r.candidates, r.text = [], "notes"
            return r

    llm = make(settings, GM(served={"gemini-3-flash"}, listing=["gemini-3-flash"]))
    g = llm.grounded_search("gemini-2.5-flash", "s", "p")
    assert g.text == "notes" and seen == ["gemini-2.5-flash", "gemini-3-flash"]


def test_image_model_name_is_replaced_too(settings, monkeypatch):
    import sys
    import types

    from lehigh_agents.imagegen import ImageGenerator

    png = b"\x89PNG\r\n\x1a\n" + b"0" * 30
    calls = []

    class Models2:
        def generate_content(self, model, contents, config):
            calls.append(model)
            if model != "gemini-3-flash-image":
                raise not_found(model)
            part = SimpleNamespace(inline_data=SimpleNamespace(data=png, mime_type="image/png"))
            return SimpleNamespace(candidates=[SimpleNamespace(content=SimpleNamespace(parts=[part]))])

        def list(self):
            return [SimpleNamespace(name="models/gemini-3-flash-image", supported_actions=["generateContent"]),
                    SimpleNamespace(name="models/gemini-3-flash", supported_actions=["generateContent"])]

    fake_genai = types.SimpleNamespace(Client=lambda **kw: SimpleNamespace(models=Models2()))
    from google import genai as real
    monkeypatch.setattr(real, "Client", fake_genai.Client)
    from dataclasses import replace

    s = replace(settings, image_provider="gemini", image_api_key="k", image_model="")
    gen = ImageGenerator(s)
    img = gen._gemini("a photo", "16:9")
    assert img.data == png and calls == ["gemini-2.5-flash-image", "gemini-3-flash-image"] and gen.model == "gemini-3-flash-image"


def test_voice_model_name_is_replaced_too(settings):
    from dataclasses import replace

    from lehigh_agents.social.voice import Narrator

    calls = []
    pcm = b"\x01\x00" * 100

    class M:
        def generate_content(self, model, contents, config):
            calls.append(model)
            if model != "gemini-3-flash-tts":
                raise not_found(model)
            blob = SimpleNamespace(data=pcm)
            return SimpleNamespace(candidates=[SimpleNamespace(content=SimpleNamespace(parts=[SimpleNamespace(inline_data=blob)]))])

        def list(self):
            return [SimpleNamespace(name="models/gemini-3-flash-tts"), SimpleNamespace(name="models/gemini-3-flash")]

    n = Narrator(replace(settings, social_voice="gemini", gemini_api_key="k"))
    n._gemini = SimpleNamespace(models=M())
    wav = n._gemini_tts("hola")
    assert wav[:4] == b"RIFF" and calls == ["gemini-2.5-flash-preview-tts", "gemini-3-flash-tts"] and n.model == "gemini-3-flash-tts"
