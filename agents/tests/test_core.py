import pytest

from lehigh_agents.llm import LLM, LLMError, extract_json, provider_for
from lehigh_agents.settings import Settings
from lehigh_agents.schemas import normalize_date, validate_auditoria, validate_hallazgos
from lehigh_agents.store import Store, canonical_url, similarity
from lehigh_agents.wordpress import normalize_root
from conftest import FakeLLM


def test_model_defaults_and_aliases(monkeypatch):
    for k in ("REDACCTOR_MODEL", "REDACTOR_MODEL"):
        monkeypatch.delenv(k, raising=False)
    assert Settings.from_env("/x").redactor_model == "gemini-1.5-flash"
    monkeypatch.setenv("REDACTOR_MODEL", "gpt-4o-mini")
    assert Settings.from_env("/x").redactor_model == "gpt-4o-mini"
    monkeypatch.setenv("REDACCTOR_MODEL", "claude-3-5-haiku-latest")  # the spelling from the spec wins
    assert Settings.from_env("/x").redactor_model == "claude-3-5-haiku-latest"


def test_problems_depend_on_provider(monkeypatch):
    monkeypatch.setenv("GEMINI_API_KEY", "g")
    monkeypatch.delenv("ANTHROPIC_API_KEY", raising=False)
    monkeypatch.setenv("REDACCTOR_MODEL", "claude-3-5-haiku-latest")
    msgs = Settings.from_env("/x").problems(need_wordpress=False)
    assert any("ANTHROPIC_API_KEY" in m for m in msgs)
    monkeypatch.setenv("AUDITOR_MODEL", "gpt-4o")
    assert any("AUDITOR_MODEL must be a Gemini" in m for m in Settings.from_env("/x").problems(False))


def test_provider_routing():
    assert provider_for("gemini-2.5-flash") == "gemini"
    assert provider_for("claude-3-5-haiku-latest") == "anthropic"
    assert provider_for("gpt-4o-mini") == "openai" and provider_for("o3-mini") == "openai"


@pytest.mark.parametrize("text,expected", [
    ('{"a": 1}', {"a": 1}),
    ('```json\n[{"a": 1}]\n```', [{"a": 1}]),
    ('Claro, aquí está: {"a": {"b": 2}} Espero que sirva', {"a": {"b": 2}}),
])
def test_extract_json(text, expected):
    assert extract_json(text) == expected


def test_extract_json_failure():
    with pytest.raises(ValueError):
        extract_json("no json here")


def test_json_repairs_once_then_fails(settings):
    llm = FakeLLM(settings, {"auditor": ["not json", {"audit_score": 90, "status": "approved", "audit_notes": []}]})
    out = llm.json("m", "Agente Auditor", "p", validate=validate_auditoria)
    assert out["audit_score"] == 90 and len(llm.calls) == 2
    bad = FakeLLM(settings, {"auditor": ["nope"]})
    with pytest.raises(LLMError):
        bad.json("m", "Agente Auditor", "p")


def test_retry_only_transient(settings):
    llm = LLM(settings, sleep=lambda s: None)
    calls = []

    def flaky():
        calls.append(1)
        raise LLMError("busy", retryable=len(calls) < 3)

    assert llm._retry.__name__ == "_retry"
    with pytest.raises(LLMError):
        llm._retry(flaky, tries=2)
    assert len(calls) == 2
    calls.clear()
    with pytest.raises(LLMError):  # non-retryable error is raised immediately
        llm._retry(lambda: (_ for _ in ()).throw(LLMError("fatal")), tries=3)


def test_validators_and_dates():
    assert validate_hallazgos([{"titulo_fuente": "t", "url": "https://a.b/c", "resumen_hechos": "x"}]) is None
    assert "http" in validate_hallazgos([{"titulo_fuente": "t", "url": "javascript:alert(1)", "resumen_hechos": "x"}])
    assert validate_auditoria({"audit_score": 101, "status": "approved"}) is not None
    assert validate_auditoria({"audit_score": 80, "status": "maybe"}) is not None
    assert normalize_date("Publicado el 2026-03-04T10:00") == "2026-03-04"
    assert normalize_date("04/03/2026") == "2026-03-04"
    assert normalize_date("ayer") == ""


def test_store_dedup(tmp_path):
    st = Store(tmp_path)
    assert canonical_url("https://www.X.com/a/?utm_source=1&b=2#f") == "https://x.com/a?b=2"
    st.remember("https://x.com/a", "Lee County approves new road for Lehigh Acres", "published")
    assert st.is_duplicate("https://www.x.com/a/?utm_campaign=z", "other")
    assert st.is_duplicate("https://y.com/b", "Lee County approves new road in Lehigh Acres")
    assert not st.is_duplicate("https://y.com/b", "School board elects new chair")
    assert similarity("road opens soon", "road opens soon") == 1.0
    st.remember("https://z.com/1", "Failed story about canal", "failed")
    assert st.is_duplicate("https://z.com/1", "x")  # fresh failure is not retried immediately
    st.db.execute("UPDATE seen SET ts = ts - 7*3600 WHERE url=?", (canonical_url("https://z.com/1"),))
    assert not st.is_duplicate("https://z.com/1", "x")  # …but is retried after 6h


def test_wp_root_normalization():
    for u in ("https://s.com", "https://s.com/", "https://s.com/wp-json", "https://s.com/wp-json/wp/v2/posts",
              "https://s.com/wp-json/"):
        assert normalize_root(u) == "https://s.com/wp-json"
    assert normalize_root("https://s.com/blog") == "https://s.com/blog/wp-json"
