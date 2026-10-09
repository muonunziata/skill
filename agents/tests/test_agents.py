import base64
import datetime as dt
import json
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest

from conftest import PNG, FakeFetcher, FakeLLM, article_page
from lehigh_agents.agents import Auditor, Rastreador, Redactor
from lehigh_agents.agents.redactor import fallback_image_prompt
from lehigh_agents.imagegen import ImageGenError, ImageGenerator, sniff_mime
from lehigh_agents.llm import Grounded
from lehigh_agents.net import FetchError
from lehigh_agents.pipeline import Pipeline
from lehigh_agents.runlog import RunLog
from lehigh_agents.schemas import Articulo, Hallazgo
from lehigh_agents.settings import Settings
from lehigh_agents.store import Store
from lehigh_agents.wordpress import WordPressClient

TODAY = dt.date.today().isoformat()
URL = "https://wink.example/news/road"
FACTS = "Lee County approved a road project on March 4. It costs 4.5 million dollars and opens in 2027."


def H(**kw):
    base = dict(titulo_fuente="Lee County approves road", url=URL, resumen_hechos=FACTS, fecha=TODAY,
                palabras_clave=["lehigh acres", "road"], source_text=FACTS, fuente="WINK News")
    base.update(kw)
    return Hallazgo(**base)


GOOD_HTML = ("<!-- wp:paragraph --><p>El condado de Lee aprobó el 4 de marzo un proyecto vial en Lehigh Acres, según WINK News. "
             "La obra costará 4.5 million dollars y abrirá en 2027.</p><!-- /wp:paragraph -->")


FILLER = "".join(f"<!-- wp:paragraph --><p>Los vecinos de la zona esperan más detalles sobre el calendario de la obra y su efecto en el tráfico diario, tema {chr(97 + i)}.</p><!-- /wp:paragraph -->" for i in range(20))


def article(**kw):
    base = dict(post_title="Aprueban nueva carretera en Lehigh Acres", post_content=GOOD_HTML + FILLER, excerpt="Obra vial aprobada.",
                meta_description="El condado aprobó una carretera en Lehigh Acres.", suggested_tags=["lehigh acres", "carretera"])
    base.update(kw)
    return Articulo(**base)


def audit_json(score=92, status="approved", notes=()):
    return {"audit_score": score, "audit_notes": list(notes), "status": status}


class Events(list):
    def __call__(self, agent, type_, message, **kw):
        self.append((agent, type_, message, kw))


# ───────────────────────── Agent 1 ─────────────────────────
def test_rastreador_builds_findings_from_pages_actually_read(settings, tmp_path):
    fetch = FakeFetcher({URL: article_page(), "https://r.example/x": "<html><title>x</title><body>short</body></html>"})
    grounded = Grounded("notes", ["lehigh acres road"], [
        {"uri": URL, "title": "wink.example", "domain": "wink.example"},
        {"uri": "https://r.example/x", "title": "r", "domain": "r"},        # too short → skipped
        {"uri": "https://facebook.com/post", "title": "fb", "domain": "fb"},  # not an article
    ])
    llm = FakeLLM(settings, {"rastreador": [{"hallazgos": [
        {"titulo_fuente": "Road approved", "url": URL, "resumen_hechos": FACTS, "fecha": TODAY, "palabras_clave": ["road", "road", "lehigh"]},
        {"titulo_fuente": "Invented story", "url": "https://made.up/story", "resumen_hechos": "x"},  # not read → dropped
    ]}]}, grounded)
    ev = Events()
    out = Rastreador(settings, llm, fetch, Store(tmp_path), ev).run()
    assert [h.url for h in out] == [URL]
    h = out[0]
    assert h.fecha == TODAY and h.palabras_clave == ["road", "lehigh"] and "Lee County" in h.source_text
    assert set(h.to_dict()) == {"titulo_fuente", "url", "resumen_hechos", "fecha", "palabras_clave", "fuente"}
    assert any(t == "search" for _, t, _, _ in ev) and any("descartada" in m for _, _, m, _ in ev)


def test_rastreador_skips_known_and_stale(settings, tmp_path, monkeypatch):
    monkeypatch.setenv("FRESHNESS_DAYS", "7")
    s = Settings.from_env("/x")
    store = Store(tmp_path)
    store.remember(URL, "Lee County approves road", "published")
    fetch = FakeFetcher({URL: article_page(), "https://b.example/old": article_page("Old")})
    grounded = Grounded("", [], [{"uri": URL, "title": "", "domain": ""}, {"uri": "https://b.example/old", "title": "", "domain": ""}])
    llm = FakeLLM(s, {"rastreador": [[
        {"titulo_fuente": "Lee County approves road", "url": URL, "resumen_hechos": "x", "fecha": TODAY},
        {"titulo_fuente": "Ancient news", "url": "https://b.example/old", "resumen_hechos": "x", "fecha": "2001-01-01"}]]}, grounded)
    assert Rastreador(s, llm, fetch, store, Events()).run() == []


def test_rastreador_no_sources(settings, tmp_path):
    llm = FakeLLM(settings, {"rastreador": [[]]}, Grounded("text", [], []))
    assert Rastreador(settings, llm, FakeFetcher(), Store(tmp_path), Events()).run() == []


# ───────────────────────── image generation ─────────────────────────
class _ImgHandler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _json(self, obj, code=200):
        d = json.dumps(obj).encode()
        self.send_response(code); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(d))); self.end_headers(); self.wfile.write(d)

    def do_POST(self):
        srv = self.server
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        srv.seen.append((self.path, dict(self.headers), body))
        if "/predictions" in self.path:  # replicate: first answer is still processing
            return self._json({"status": "processing", "urls": {"get": f"http://127.0.0.1:{srv.server_port}/poll"}})
        if self.path == "/v1/images/generations":
            if srv.mode == "bad":
                return self._json({"data": [{"b64_json": base64.b64encode(b"not an image").decode()}]})
            return self._json({"data": [{"b64_json": base64.b64encode(PNG).decode()}]})
        self._json({"error": "x"}, 404)

    def do_GET(self):
        srv = self.server
        if self.path == "/poll":
            srv.polls += 1
            return self._json({"status": "succeeded", "output": [f"http://127.0.0.1:{srv.server_port}/out.png"]})
        if self.path == "/out.png":
            self.send_response(200); self.send_header("Content-Type", "image/png")
            self.send_header("Content-Length", str(len(PNG))); self.end_headers(); self.wfile.write(PNG)
            return
        self._json({}, 404)


@pytest.fixture
def img_server():
    srv = HTTPServer(("127.0.0.1", 0), _ImgHandler)
    srv.seen, srv.polls, srv.mode = [], 0, "ok"
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    yield srv
    srv.shutdown()


def _img_settings(monkeypatch, srv, provider, model=""):
    from lehigh_agents.net import Fetcher
    monkeypatch.setenv("IMAGE_PROVIDER", provider)
    monkeypatch.setenv("IMAGE_API_KEY", "k-123")
    monkeypatch.setenv("IMAGE_MODEL", model)
    monkeypatch.setenv("IMAGE_API_ENDPOINT", f"http://127.0.0.1:{srv.server_port}" + ("/v1/models/{model}/predictions" if provider == "replicate" else "/v1/images/generations"))
    monkeypatch.setenv("IMAGE_EXTRA_INPUT", '{"output_quality": 90}')
    return Settings.from_env("/x"), Fetcher(allow_private=True)


def test_replicate_polls_downloads_and_verifies(monkeypatch, img_server):
    s, f = _img_settings(monkeypatch, img_server, "replicate", "black-forest-labs/flux-1.1-pro")
    gen = ImageGenerator(s, fetcher=f, sleep=lambda x: None)
    img = gen.generate("A photorealistic wide photograph of a quiet street in Lehigh Acres at golden hour")
    path, headers, body = img_server.seen[0]
    assert path == "/v1/models/black-forest-labs/flux-1.1-pro/predictions"
    assert headers["Authorization"] == "Bearer k-123"
    assert body["input"]["aspect_ratio"] == "16:9" and body["input"]["output_quality"] == 90
    assert img_server.polls == 1 and img.mime == "image/png" and img.width == 800 and img.remote_url.endswith("/out.png")


def test_openai_compatible_b64_and_rejects_non_images(monkeypatch, img_server):
    s, f = _img_settings(monkeypatch, img_server, "openai", "gpt-image-1")
    gen = ImageGenerator(s, fetcher=f)
    img = gen.generate("A photorealistic photograph of a canal at sunset in Southwest Florida")
    assert img.data == PNG and img_server.seen[0][2]["size"] == "1536x1024"
    img_server.mode = "bad"
    with pytest.raises(ImageGenError, match="not a JPEG, PNG or WebP"):
        gen.generate("A photorealistic photograph of a canal at sunset in Southwest Florida")


def test_image_generator_disabled_and_provider_inference(monkeypatch):
    for k in ("IMAGE_API_KEY", "IMAGE_PROVIDER", "IMAGE_API_ENDPOINT"):
        monkeypatch.delenv(k, raising=False)
    assert not ImageGenerator(Settings.from_env("/x")).enabled
    monkeypatch.setenv("IMAGE_API_KEY", "k")
    assert Settings.from_env("/x").image_provider == "replicate"
    monkeypatch.setenv("IMAGE_API_ENDPOINT", "https://api.together.xyz/v1/images/generations")
    assert Settings.from_env("/x").image_provider == "openai"
    assert sniff_mime(PNG) == "image/png" and sniff_mime(b"GIF89a") == ""


# ───────────────────────── Agent 2 ─────────────────────────
ARTICLE_JSON = {
    "post_title": "Aprueban nueva carretera en Lehigh Acres",
    "post_content": GOOD_HTML + FILLER + "<p>[[IMAGEN: calle residencial en Lehigh Acres]]</p>"
                    "<!-- wp:paragraph --><p>[[VIDEO: https://youtu.be/dQw4w9WgXcQ]]</p><!-- /wp:paragraph -->"
                    "<script>alert(1)</script><p onclick='x()'>fin</p>",
    "excerpt": "Obra vial aprobada.", "meta_description": "Carretera aprobada en Lehigh Acres.",
    "suggested_tags": ["Lehigh Acres", "carretera", "lehigh acres"], "featured_image_url": "https://hallucinated.example/x.jpg",
}
IMG_PROMPT = ("Photorealistic wide editorial photograph of a quiet residential street in Lehigh Acres, Southwest Florida, with "
              "one-storey stucco ranch homes, slash pines and cabbage palms, a drainage canal behind. Late afternoon golden hour, "
              "soft low sun, long shadows, clear subtropical sky. Shot on a Canon EOS R5, 35mm lens, f/4, eye-level, wide 16:9 "
              "composition with foreground, midground and background. Documentary photojournalism, natural colour, subtle film grain. "
              "No text, no logos, no watermark.")


def make_redactor(settings, llm, fetch, wp=None, images=True, imagegen=None):
    ig = imagegen or type("IG", (), {"enabled": False, "provider": "", "model": ""})()
    return Redactor(settings, llm, ig, fetch, Events(), wp, images)


class FakeGen:
    enabled, provider, model = True, "replicate", "flux"

    def __init__(self):
        self.prompts = []

    def generate(self, prompt, aspect_ratio=None):
        from lehigh_agents.imagegen import GeneratedImage
        self.prompts.append(prompt)
        return GeneratedImage(PNG, "image/png", "replicate", "flux", remote_url="https://tmp.replicate.delivery/a.png",
                              width=800, height=450, seconds=1.2)


def test_redactor_article_sanitising_placeholders_and_ai_image(settings, wp_server):
    fetch = FakeFetcher({"https://www.youtube.com/oembed?format=json&url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3DdQw4w9WgXcQ":
                         json.dumps({"title": "Clip", "author_name": "WINK", "thumbnail_url": "t"})})
    llm = FakeLLM(settings, {"redactor": [ARTICLE_JSON], "imagen": [{"image_prompt": IMG_PROMPT, "alt_text": "Calle residencial al atardecer", "sensitive": False}]})
    wp = WordPressClient(settings.wp_rest_url, settings.wp_auth_token)
    gen = FakeGen()
    art = make_redactor(settings, llm, fetch, wp, True, gen).run(H())
    assert "<script" not in art.post_content and "onclick" not in art.post_content
    assert "wp:embed" in art.post_content and "dQw4w9WgXcQ" in art.post_content            # verified video → embed block
    assert "lnh-placeholder imagen" in art.post_content and "imagen: calle residencial en Lehigh Acres" in art.media_todo
    assert "[[" not in art.post_content
    assert art.suggested_tags == ["lehigh acres", "carretera"]
    # AI featured image: URL comes from the WordPress media library, not from the model's hallucinated URL
    assert art.featured_image_ai and art.featured_media_id == 77
    assert art.featured_image_url == f"http://127.0.0.1:{wp_server.server_port}/uploads/img77.png"
    assert art.featured_image_caption == "Imagen ilustrativa generada con IA"
    assert gen.prompts == [IMG_PROMPT] and art.featured_image_prompt == IMG_PROMPT and art.image_provider == "replicate"
    upload = next(r for r in wp_server.requests if r["path"].endswith("/wp/v2/media") and r["method"] == "POST")
    assert upload["body"] == PNG and upload["auth"].startswith("Basic ")


def test_unsafe_image_prompt_is_replaced_by_neutral_scene(settings, wp_server):
    bad = {"image_prompt": IMG_PROMPT.replace("residential street", "crime scene with a victim and a child"), "alt_text": "x", "sensitive": True}
    llm = FakeLLM(settings, {"redactor": [ARTICLE_JSON], "imagen": [bad]})
    gen = FakeGen()
    art = make_redactor(settings, llm, FakeFetcher(), None, True, gen).run(H())
    assert gen.prompts[0] == fallback_image_prompt(H()) and "victim" not in gen.prompts[0]
    assert art.featured_image_ai


def test_non_english_prompt_rejected_and_image_failure_is_not_fatal(settings):
    es = {"image_prompt": "Una fotografía fotorrealista de una calle tranquila en Lehigh Acres al atardecer con casas bajas de estuco y palmeras, luz dorada suave, cielo despejado, composición panorámica.", "alt_text": "x"}
    llm = FakeLLM(settings, {"redactor": [ARTICLE_JSON], "imagen": [es]})
    gen = FakeGen()
    make_redactor(settings, llm, FakeFetcher(), None, True, gen).run(H())
    assert gen.prompts[0] == fallback_image_prompt(H())

    class Boom(FakeGen):
        def generate(self, *a, **k):
            raise ImageGenError("quota exceeded")

    art = make_redactor(settings, FakeLLM(settings, {"redactor": [ARTICLE_JSON], "imagen": [es]}), FakeFetcher(), None, True, Boom()).run(H())
    assert art.featured_image_url == "" and not art.featured_image_ai and "imagen destacada" in art.media_todo


def test_dry_run_saves_image_locally(settings, tmp_path):
    llm = FakeLLM(settings, {"redactor": [ARTICLE_JSON], "imagen": [{"image_prompt": IMG_PROMPT, "alt_text": "alt"}]})
    art = make_redactor(settings, llm, FakeFetcher(), None, True, FakeGen()).run(H())
    assert art.featured_image_url == "https://tmp.replicate.delivery/a.png"
    assert list((settings.state_dir / "images").glob("*.png"))


def test_commons_fallback_when_no_ai_image(settings):
    commons = json.dumps({"query": {"pages": {"1": {"title": "File:Lehigh_Acres_canal.jpg", "imageinfo": [{
        "mime": "image/jpeg", "width": 2000, "height": 1200, "thumburl": "https://upload.wikimedia.org/t.jpg", "thumbwidth": 1280, "thumbheight": 768,
        "descriptionurl": "https://commons.wikimedia.org/wiki/File:x", "url": "https://upload.wikimedia.org/o.jpg",
        "extmetadata": {"LicenseShortName": {"value": "CC BY-SA 4.0"}, "Artist": {"value": "<a>Jane Doe</a>"}, "ImageDescription": {"value": "A canal"}}}]}}}})
    fetch = FakeFetcher()
    fetch.get_orig = fetch.get
    def get(url, max_bytes=None, accept="*/*"):
        if "commons.wikimedia.org" in url:
            from lehigh_agents.net import FetchResult
            return FetchResult(url, 200, "application/json", commons.encode())
        return FakeFetcher.get(fetch, url, max_bytes, accept)
    fetch.get = get
    llm = FakeLLM(settings, {"redactor": [ARTICLE_JSON]})
    art = make_redactor(settings, llm, fetch).run(H())
    assert art.featured_image_url == "https://upload.wikimedia.org/t.jpg" and "Jane Doe" in art.featured_image_credit
    assert "wp:image" in art.post_content and "Wikimedia Commons" in art.post_content


# ───────────────────────── Agent 3 ─────────────────────────
def make_auditor(settings, llm, fetch, wp=None):
    ev = Events()
    return Auditor(settings, llm, fetch, ev, wp), ev


def test_auditor_approves_clean_article_and_posts_draft(settings, wp_server):
    fetch = FakeFetcher({URL: article_page()})
    wp = WordPressClient(settings.wp_rest_url, settings.wp_auth_token)
    aud_agent, _ = make_auditor(settings, FakeLLM(settings, {"auditor": [audit_json(93)]}), fetch, wp)
    art = article(featured_image_url="", featured_image_ai=False)
    aud = aud_agent.audit(H(), art)
    assert aud.status == "approved" and aud.audit_score == 93 and aud.checks["links"]["broken"] == []
    out = aud_agent.review_and_publish(H(), art, aud, run_id="r1", trace=[{"agent": "x"}])
    assert out["posted"] and out["post"]["status"] == "draft"
    post = wp_server.posts[0]
    assert post["status"] == "draft" and post["title"] == art.post_title and post["tags"] == [11, 12]
    assert post["meta"]["lnh_audit_score"] == 93 and post["meta"]["lnh_audit_status"] == "approved"
    assert json.loads(post["meta"]["lnh_keywords"]) == ["lehigh acres", "road"] and post["meta"]["lnh_source_url"] == URL
    assert post["slug"] == "aprueban-nueva-carretera-en-lehigh-acres"


def test_auditor_flags_invented_figures_broken_links_and_unsafe_html(settings):
    fetch = FakeFetcher({URL: article_page()})
    bad = article(post_content=GOOD_HTML + FILLER + '<p>Costará 9,999 millones. <a href="https://gone.example/x">ver</a></p><script>x</script>')
    agent, _ = make_auditor(settings, FakeLLM(settings, {"auditor": [audit_json(95, "approved")]}), fetch)
    aud = agent.audit(H(), bad)
    assert aud.status == "flagged" and aud.audit_score < 80
    notes = " ".join(aud.audit_notes)
    assert "Enlace roto: https://gone.example/x" in notes and "9,999" in notes and "HTML inseguro" in notes


def test_auditor_model_flag_and_model_outage_never_approve(settings):
    fetch = FakeFetcher({URL: article_page()})
    a1, _ = make_auditor(settings, FakeLLM(settings, {"auditor": [audit_json(70, "flagged", ["Dato inventado: …"])]}), fetch)
    r1 = a1.audit(H(), article())
    assert r1.status == "flagged" and "Dato inventado" in r1.audit_notes[0]
    a2, _ = make_auditor(settings, FakeLLM(settings, {"auditor": ["garbage"]}), fetch)
    r2 = a2.audit(H(), article())
    assert r2.status == "flagged" and any("auditoría con IA no pudo completarse" in n for n in r2.audit_notes)


def test_bot_blocked_links_are_not_reported_as_broken(settings):
    fetch = FakeFetcher({URL: article_page(), "https://blocked.example/a": FetchError("HTTP 403", 403)})
    agent, _ = make_auditor(settings, FakeLLM(settings, {"auditor": [audit_json(90)]}), fetch)
    aud = agent.audit(H(), article(post_content=GOOD_HTML + FILLER + '<p><a href="https://blocked.example/a">x</a></p>'))
    assert aud.checks["links"]["broken"] == [] and aud.checks["links"]["unverifiable"] == ["https://blocked.example/a"]
    assert aud.status == "approved"


def test_flagged_is_not_posted_and_uploaded_image_is_removed(settings, wp_server):
    fetch = FakeFetcher({URL: article_page()})
    wp = WordPressClient(settings.wp_rest_url, settings.wp_auth_token)
    agent, _ = make_auditor(settings, FakeLLM(settings, {"auditor": [audit_json(40, "flagged", ["mal"])]}), fetch, wp)
    art = article(featured_media_id=77, featured_image_ai=True, featured_image_caption="IA")
    aud = agent.audit(H(), art)
    out = agent.review_and_publish(H(), art, aud)
    assert not out["posted"] and out["reason"] == "flagged" and wp_server.posts == []
    assert wp_server.deleted == ["/wp-json/wp/v2/media/77"]


def test_post_flagged_option_sends_pending(settings, wp_server, monkeypatch):
    monkeypatch.setenv("POST_FLAGGED", "true")
    s = Settings.from_env("/x")
    wp = WordPressClient(s.wp_rest_url, s.wp_auth_token)
    agent, _ = make_auditor(s, FakeLLM(s, {"auditor": [audit_json(50, "flagged", ["x"])]}), FakeFetcher({URL: article_page()}), wp)
    art = article()
    out = agent.review_and_publish(H(), art, agent.audit(H(), art))
    assert out["posted"] and wp_server.posts[0]["status"] == "pending" and wp_server.posts[0]["meta"]["lnh_audit_status"] == "flagged"


# ───────────────────────── full pipeline ─────────────────────────
def test_pipeline_end_to_end_with_revision_loop(settings, wp_server, tmp_path):
    fetch = FakeFetcher({URL: article_page()})
    grounded = Grounded("n", ["lehigh acres road"], [{"uri": URL, "title": "wink", "domain": "wink"}])
    llm = FakeLLM(settings, {
        "rastreador": [[{"titulo_fuente": "Lee County approves road", "url": URL, "resumen_hechos": FACTS, "fecha": TODAY, "palabras_clave": ["road"]}]],
        "redactor": [ARTICLE_JSON, {**ARTICLE_JSON, "post_title": "Aprueban carretera corregida"}],
        "imagen": [{"image_prompt": IMG_PROMPT, "alt_text": "alt"}],
        "auditor": [audit_json(55, "flagged", ["Cifra inventada en el segundo párrafo"]), audit_json(91)],
    }, grounded)
    pipe = Pipeline(settings, llm=llm, wp=WordPressClient(settings.wp_rest_url, settings.wp_auth_token), imagegen=FakeGen(), fetcher=fetch)
    report = pipe.run_once()
    item = report["items"][0]
    assert report["counts"] == {"approved": 1, "flagged": 0, "failed": 0, "found": 1}
    assert item["revisions"] == 1 and item["audit_score"] == 91 and item["posted"]
    post = wp_server.posts[0]
    assert post["title"] == "Aprueban carretera corregida" and post["featured_media"] == 77   # image kept across the revision
    assert post["meta"]["lnh_featured_image_ai"] is True and post["meta"]["lnh_revisions"] == 1
    trace = json.loads(post["meta"]["lnh_trace"])
    assert {"rastreador", "redactor", "auditor"} <= {e["agent"] for e in trace}
    run_report = next(r for r in wp_server.requests if r["path"].endswith("/lnh/v1/runs"))
    assert json.loads(run_report["body"])["counts"]["approved"] == 1
    assert (settings.state_dir / "runs" / f"{report['run_id']}.json").exists()
    # the story is remembered: a second run finds nothing new
    assert Pipeline(settings, llm=llm, wp=WordPressClient(settings.wp_rest_url, settings.wp_auth_token),
                    imagegen=FakeGen(), fetcher=fetch).run_once()["counts"]["found"] == 0


def test_pipeline_dry_run_writes_nothing_to_wordpress(settings, wp_server):
    fetch = FakeFetcher({URL: article_page()})
    grounded = Grounded("n", [], [{"uri": URL, "title": "w", "domain": "w"}])
    llm = FakeLLM(settings, {"rastreador": [[{"titulo_fuente": "Road", "url": URL, "resumen_hechos": FACTS}]],
                             "redactor": [ARTICLE_JSON], "imagen": [{"image_prompt": IMG_PROMPT, "alt_text": "a"}],
                             "auditor": [audit_json(90)]}, grounded)
    report = Pipeline(settings, llm=llm, imagegen=FakeGen(), fetcher=fetch, dry_run=True).run_once()
    assert report["items"][0]["status"] == "approved" and not report["items"][0]["posted"]
    assert wp_server.requests == []


def test_pipeline_survives_a_failing_story(settings, wp_server):
    fetch = FakeFetcher({URL: article_page()})
    grounded = Grounded("n", [], [{"uri": URL, "title": "w", "domain": "w"}])
    llm = FakeLLM(settings, {"rastreador": [[{"titulo_fuente": "Road", "url": URL, "resumen_hechos": FACTS}]],
                             "redactor": ["this is not json"]}, grounded)
    report = Pipeline(settings, llm=llm, wp=WordPressClient(settings.wp_rest_url, settings.wp_auth_token), imagegen=FakeGen(), fetcher=fetch).run_once()
    assert report["counts"]["failed"] == 1 and "invalid structured output" in report["items"][0]["error"]


# ───────────────────────── robustness additions ─────────────────────────
def test_number_check_accepts_equivalent_spellings():
    from lehigh_agents.checks import unsupported_numbers
    evidence = "El proyecto cuesta 4,5 millones de dólares, afecta a 1.200 familias y cubre 12.5 millas. Presupuesto $3,000."
    article = "Costará 4.5 millones; 1,200 familias; 12,5 millas; $3.000. Pero también 777 viviendas y el 45%."
    assert unsupported_numbers(article, evidence) == ["777", "45%"]
    assert unsupported_numbers("En 2026 hubo 3 reuniones y 7 votos.", "") == []  # years and 1-digit numbers are ignored


def test_shortcodes_in_agent_html_are_neutralised_but_placeholders_survive():
    from lehigh_agents.htmlutil import sanitize_html
    out = sanitize_html("<p>[gallery ids=\"1,2\"] y [plugin_tag a=b] [/x] [[IMAGEN: foto]] [[VIDEO: https://youtu.be/x]]</p>")
    assert "[gallery" not in out and "&#91;gallery" in out and "&#91;/x&#93;" in out
    assert "[[IMAGEN: foto]]" in out and "[[VIDEO: https://youtu.be/x]]" in out


def test_language_is_sent_and_orphan_media_removed_on_failure(settings, wp_server):
    fetch = FakeFetcher({URL: article_page()})
    grounded = Grounded("n", [], [{"uri": URL, "title": "w", "domain": "w"}])
    # the auditor model is fine, but WordPress rejects the post -> the uploaded image must be deleted again
    llm = FakeLLM(settings, {"rastreador": [[{"titulo_fuente": "Road", "url": URL, "resumen_hechos": FACTS, "fecha": TODAY}]],
                             "redactor": [ARTICLE_JSON], "imagen": [{"image_prompt": IMG_PROMPT, "alt_text": "a"}],
                             "auditor": [audit_json(92)]}, grounded)
    wp = WordPressClient(settings.wp_rest_url, settings.wp_auth_token)
    original = wp.create_post
    wp.create_post = lambda payload: (_ for _ in ()).throw(__import__("lehigh_agents.wordpress", fromlist=["WPError"]).WPError("HTTP 500"))
    report = Pipeline(settings, llm=llm, wp=wp, imagegen=FakeGen(), fetcher=fetch).run_once()
    assert report["counts"]["failed"] == 1 and wp_server.deleted == ["/wp-json/wp/v2/media/77"]
    wp.create_post = original
    # success path carries the language
    llm2 = FakeLLM(settings, {"rastreador": [[{"titulo_fuente": "Other story", "url": URL + "2", "resumen_hechos": FACTS, "fecha": TODAY}]],
                              "redactor": [ARTICLE_JSON], "imagen": [{"image_prompt": IMG_PROMPT, "alt_text": "a"}], "auditor": [audit_json(92)]},
                   Grounded("n", [], [{"uri": URL + "2", "title": "w", "domain": "w"}]))
    Pipeline(settings, llm=llm2, wp=wp, imagegen=FakeGen(), fetcher=FakeFetcher({URL + "2": article_page()})).run_once()
    assert wp_server.posts[-1]["meta"]["lnh_language"] == "es"


def test_env_file_discovery_prefers_cwd(tmp_path, monkeypatch):
    for k in ("GEMINI_API_KEY", "REDACCTOR_MODEL", "REDACTOR_MODEL", "GOOGLE_API_KEY"):
        monkeypatch.delenv(k, raising=False)
    (tmp_path / ".env").write_text("GEMINI_API_KEY=from-dotenv\nREDACCTOR_MODEL=gpt-4o-mini\n")
    monkeypatch.chdir(tmp_path)
    s = Settings.from_env()
    assert s.gemini_api_key == "from-dotenv" and s.redactor_model == "gpt-4o-mini"
    monkeypatch.setenv("GEMINI_API_KEY", "from-env")  # real environment wins over the file
    assert Settings.from_env().gemini_api_key == "from-env"


# ───────────────────────── regressions found in review ─────────────────────────
def test_sanitizer_removes_whole_blocks_and_separatorless_event_handlers():
    from lehigh_agents.htmlutil import sanitize_html, unsafe_html_problems
    out = sanitize_html('<p>a</p><script>alert(1)</script><style>p{display:none}</style><iframe src="//x"></iframe><p>b</p>')
    assert out == "<p>a</p><p>b</p>"
    for evil in ('<a href="x"onclick="e()">k</a>', "<img/src=x/onerror=alert(1)>", "<p onclick='x()'>k</p>"):
        assert unsafe_html_problems(sanitize_html(evil)) == []
        assert "onerror" not in sanitize_html(evil) and "onclick" not in sanitize_html(evil)
    assert unsafe_html_problems("<img src=x onerror=alert(1)>")
    assert unsafe_html_problems("<p>one = two, see https://x.example/online=1</p>") == []


def test_env_example_defaults_run_without_an_image_key(monkeypatch, tmp_path):
    import shutil
    from pathlib import Path
    for k in ("IMAGE_API_KEY", "IMAGE_PROVIDER", "IMAGE_API_ENDPOINT", "IMAGE_MODEL", "GOOGLE_API_KEY", "REDACCTOR_MODEL",
              "REDACTOR_MODEL", "RASTREADOR_MODEL", "AUDITOR_MODEL"):
        monkeypatch.delenv(k, raising=False)  # other tests load .env files straight into os.environ
    env = tmp_path / ".env"
    shutil.copy(Path(__file__).resolve().parent.parent / ".env.example", env)
    monkeypatch.setenv("GEMINI_API_KEY", "g")
    monkeypatch.setenv("WP_REST_URL", "https://x.example")
    monkeypatch.setenv("WP_AUTH_TOKEN", "u:p")
    s = Settings.from_env(env)
    assert s.image_provider == "" and s.problems() == []  # "leave IMAGE_API_KEY empty to disable"
    monkeypatch.setenv("IMAGE_API_KEY", "k")
    assert Settings.from_env(env).image_provider == "replicate"


def test_malformed_urls_are_fetch_errors_not_crashes():
    from lehigh_agents.net import Fetcher
    for url in ("http://example.com:abc/", "http://" + "a" * 70 + ".com/", "http://[::1/"):
        with pytest.raises(FetchError):
            Fetcher().get(url)


def test_timeouts_and_blocked_hosts_are_not_broken_links(settings):
    from lehigh_agents.checks import check_links
    fetch = FakeFetcher({"https://slow.example/a": FetchError("network error: timed out", inconclusive=True),
                         "https://nxdomain.example/a": FetchError("cannot resolve nxdomain.example")})
    res = {r.url: r.state for r in check_links(fetch, ["https://slow.example/a", "https://nxdomain.example/a", "https://gone.example/a"])}
    assert res == {"https://slow.example/a": "unverifiable", "https://nxdomain.example/a": "broken", "https://gone.example/a": "broken"}


def test_dry_run_does_not_consume_stories_for_the_real_run(settings, wp_server):
    fetch = FakeFetcher({URL: article_page()})
    grounded = Grounded("n", [], [{"uri": URL, "title": "w", "domain": "w"}])

    def llm():
        return FakeLLM(settings, {"rastreador": [[{"titulo_fuente": "Road", "url": URL, "resumen_hechos": FACTS}]],
                                  "redactor": [ARTICLE_JSON], "imagen": [{"image_prompt": IMG_PROMPT, "alt_text": "a"}],
                                  "auditor": [audit_json(90)]}, grounded)

    assert Pipeline(settings, llm=llm(), imagegen=FakeGen(), fetcher=fetch, dry_run=True).run_once()["counts"]["found"] == 1
    real = Pipeline(settings, llm=llm(), wp=WordPressClient(settings.wp_rest_url, settings.wp_auth_token), imagegen=FakeGen(), fetcher=fetch)
    assert real.run_once()["counts"]["approved"] == 1


def test_token_usage_is_reported_per_run(settings, wp_server):
    llm = FakeLLM(settings, {"rastreador": [[]]}, Grounded("n", [], []))
    pipe = Pipeline(settings, llm=llm, wp=WordPressClient(settings.wp_rest_url, settings.wp_auth_token), imagegen=FakeGen(), fetcher=FakeFetcher())
    llm._track("m", 100, 10)
    first = {k: dict(v) for k, v in pipe.run_once()["usage"].items()}
    assert first == {}  # usage recorded before the run started does not leak into its report
    llm._track("m", 5, 1)
    assert pipe.run_once()["usage"] == {} and llm.usage == {}


def test_revision_keeps_pending_media_todos_and_neutral_alt_text(settings):
    llm = FakeLLM(settings, {"redactor": [ARTICLE_JSON]})
    red = make_redactor(settings, llm, FakeFetcher())
    art = article(media_todo=["imagen destacada", "imagen: calle"], featured_image_url="https://x.example/i.jpg")
    new = red.revise(H(), art, ["nota"])
    assert "imagen destacada" in new.media_todo and "imagen: calle" in new.media_todo
    assert len(new.media_todo) == len(set(new.media_todo))
    bad = {"image_prompt": IMG_PROMPT.replace("residential street", "crime scene with a victim and a child"),
           "alt_text": "Un niño víctima en la escena del crimen", "sensitive": True}
    _, alt, _ = make_redactor(settings, FakeLLM(settings, {"imagen": [bad]}), FakeFetcher()).build_image_prompt(H(), art)
    assert "niño" not in alt and "Lehigh Acres" in alt


def test_placeholder_text_cannot_close_its_html_comment():
    from lehigh_agents.agents.redactor import _comment_text
    assert "--" not in _comment_text("foto --- de --->la calle") and ">" not in _comment_text("a --> b")
