import hashlib
import hmac
import io
import json
import shutil
import subprocess
from dataclasses import replace
from pathlib import Path

import pytest

from conftest import PNG, FakeFetcher, FakeLLM
from lehigh_agents.llm import LLMError
from lehigh_agents.social import ArticleContext, SocialDesigner, SocialError
from lehigh_agents.social import plan as planmod
from lehigh_agents.social.brand import ASSETS, RING, load_brand
from lehigh_agents.social.render import SlideRenderer, SocialRenderError, prepare_photo
from lehigh_agents.social.templates import FORMATS, scene_html, slide_html
from lehigh_agents.social.video import VideoError, build_video, ffmpeg_path
from lehigh_agents.social.voice import Narrator, VoiceError, build_track, pcm_to_wav, wav_info
from lehigh_agents.wordpress import WordPressClient

ROOT = Path(__file__).resolve().parents[2]


def _have_browser() -> bool:
    try:
        with SlideRenderer():
            return True
    except SocialRenderError:
        return False


needs_browser = pytest.mark.skipif(not _have_browser(), reason="Chromium not available")


def _have_ffmpeg() -> bool:
    try:
        ffmpeg_path()
        return True
    except VideoError:
        return False


needs_ffmpeg = pytest.mark.skipif(not _have_ffmpeg(), reason="ffmpeg not available")

BODY = ("El condado de Lee aprobó el 4 de marzo un proyecto vial en Lehigh Acres, según WINK News. La obra costará 4.5 millones "
        "de dólares, tendrá cuatro carriles, aceras y una ciclovía, y abrirá en 2027.")


def plan_json(**over):
    d = {
        "hook": "Cuatro carriles para Homestead Rd.",
        "slides": [
            {"kind": "cover", "headline": "Así será la nueva carretera de Lehigh Acres", "body": "Obra aprobada por el condado"},
            {"kind": "point", "headline": "Qué cambia", "body": "Cuatro carriles, aceras y ciclovía."},
            {"kind": "stat", "headline": "Inversión", "stat": "4.5", "stat_label": "millones de dólares", "body": "Costo de la obra"},
            {"kind": "point", "headline": "Cuándo abre", "body": "Se prevé para 2027."},
            {"kind": "source", "headline": "Fuente de la información", "body": "WINK News"},
            {"kind": "cta", "headline": "Lee la nota completa", "body": "Enlace en la bio"},
        ],
        "scenes": [{"narration": "Lehigh Acres tendrá una carretera nueva.", "text": "Nueva carretera"},
                   {"narration": "Tendrá cuatro carriles, aceras y ciclovía.", "text": "4 carriles + ciclovía"},
                   {"narration": "La obra costará 4.5 millones de dólares.", "text": "4.5 millones"},
                   {"narration": "Abrirá en 2027, según WINK News.", "text": "Abre en 2027"}],
        "instagram_caption": "Lehigh Acres tendrá una carretera nueva.\n\nLee la nota completa en el enlace de la bio.",
        "tiktok_caption": "Carretera nueva en Lehigh Acres",
        "hashtags": ["LehighAcres", "#Florida", "noticias locales"],
        "alt_text": "Portada: nueva carretera en Lehigh Acres",
    }
    d.update(over)
    return d


# ───────────────────────── plan ─────────────────────────
def test_plan_validation_enforces_structure():
    assert planmod.validate_plan(plan_json()) is None
    assert "between 5" in planmod.validate_plan(plan_json(slides=plan_json()["slides"][:3]))
    bad = plan_json()
    bad["slides"][0]["kind"] = "point"
    assert "start with 'cover'" in planmod.validate_plan(bad)
    bad = plan_json()
    bad["slides"][2] = {"kind": "stat", "headline": "x"}
    assert "needs a 'stat'" in planmod.validate_plan(bad)
    assert "scenes" in planmod.validate_plan(plan_json(scenes=[]))
    assert "hook" in planmod.validate_plan(plan_json(hook=" "))
    assert planmod.validate_plan([]) == "expected a JSON object"


def test_plan_fields_are_clipped_and_hashtags_normalised():
    d = plan_json()
    d["slides"][1]["headline"] = "palabra " * 40
    plan = planmod.parse_plan(d)
    assert len(plan.slides[1].headline) <= planmod.LIMITS["headline"] + 1 and plan.slides[1].headline.endswith("…")
    assert plan.hashtags == ["#LehighAcres", "#Florida", "#noticiaslocales"]
    assert planmod.normalize_hashtags(["a", "#A", "2026"], ["B"]) == ["#a", "#B"]


def test_plan_rejects_figures_not_in_the_article():
    d = plan_json()
    d["slides"][3]["body"] = "Se prevé para 2027 con 1,250 empleos."
    plan = planmod.parse_plan(d)
    assert "1,250" in planmod.semantic_problems(plan, BODY)
    assert planmod.semantic_problems(planmod.parse_plan(plan_json()), BODY) is None


# ───────────────────────── brand ─────────────────────────
def test_brand_assets_match_the_official_logos():
    brand_dir = ROOT / "brand"
    for name in ("golehighacres-logo-horizontal.png", "golehighacres-logo-vertical.png", "golehighacres-symbol.png",
                 "golehighacres-wordmark-plain.png"):
        assert (ASSETS / name).read_bytes() == (brand_dir / name).read_bytes(), name
    assert (ASSETS / "fonts" / "OFL-Poppins.txt").exists()


def test_load_brand_defaults_and_custom_logo(settings, tmp_path):
    b = load_brand(settings)
    assert b.name == "GoLehighAcres.org" and b.color == "#1b6a55" and b.accent == "#ff5757"
    assert b.logo_h.startswith("data:image/png;base64,") and b.logo_v.startswith("data:image/png;base64,")
    assert "@font-face" in b.font_css and len(RING) == 9
    custom = tmp_path / "logo.png"
    custom.write_bytes(PNG)
    s2 = replace(settings, brand_logo=str(custom))
    assert load_brand(s2).logo_h != b.logo_h
    assert load_brand(replace(settings, brand_logo="/does/not/exist.png")).logo_h == b.logo_h  # falls back to the official logo


def test_settings_validate_brand_colours(monkeypatch, settings):
    from lehigh_agents.settings import Settings

    monkeypatch.setenv("BRAND_COLOR", "not-a-colour")
    monkeypatch.setenv("SOCIAL_THEME", "neon")
    monkeypatch.setenv("SOCIAL_VOICE", "robot")
    s = Settings.from_env("/nonexistent.env")
    assert s.brand_color == "#1b6a55" and s.social_theme == "light" and s.social_voice == "none"


# ───────────────────────── templates & rendering ─────────────────────────
def _slides():
    return planmod.parse_plan(plan_json()).slides


def test_templates_escape_text_and_include_brand(settings):
    brand = load_brand(settings)
    sl = planmod.Slide(kind="point", headline='<script>alert(1)</script> & "x"', body="<b>hola</b>")
    html = slide_html(sl, index=1, total=6, fmt="instagram", brand=brand)
    assert "<script>alert" not in html and "&lt;script&gt;" in html
    assert brand.logo_h in html and "02/06" in html
    cover = slide_html(_slides()[0], index=0, total=6, fmt="tiktok", brand=brand, photo="data:image/jpeg;base64,AAA",
                       ai_label="Ilustración generada con IA", credit="Foto: X")
    assert "Ilustración generada con IA" in cover and "Foto: X" in cover and "data:image/jpeg;base64,AAA" in cover
    assert "Desliza" not in slide_html(_slides()[-1], index=5, total=6, fmt="instagram", brand=brand)
    assert "Swipe" in slide_html(_slides()[1], index=1, total=6, fmt="instagram", brand=brand, lang="en")
    assert brand.logo_v in scene_html("Gracias", index=4, total=5, brand=brand, outro=True)


@needs_browser
@pytest.mark.parametrize("theme", ["light", "dark", "brand"])
def test_every_slide_kind_renders_at_exact_size(settings, theme):
    from PIL import Image

    brand = load_brand(settings)
    slides = _slides()
    with SlideRenderer() as r:
        for fmt in ("instagram", "tiktok"):
            for i, sl in enumerate(slides):
                png = r.png(slide_html(sl, index=i, total=len(slides), fmt=fmt, brand=brand, theme=theme), FORMATS[fmt]["w"], FORMATS[fmt]["h"])
                img = Image.open(io.BytesIO(png))
                assert img.size == (FORMATS[fmt]["w"], FORMATS[fmt]["h"]), (fmt, sl.kind)
                assert len(img.convert("RGB").resize((24, 24)).getcolors(10000)) > 20  # not a blank frame
        png = r.png(scene_html("Texto de escena", index=0, total=3, brand=brand, theme=theme), 1080, 1920)
        assert Image.open(io.BytesIO(png)).size == (1080, 1920)


@needs_browser
def test_overlong_text_is_fitted_not_overflowing(settings):
    from PIL import Image

    brand = load_brand(settings)
    sl = planmod.Slide(kind="point", headline="titular larguísimo " * 12, body="cuerpo larguísimo " * 40)
    with SlideRenderer() as r:
        img = Image.open(io.BytesIO(r.png(slide_html(sl, index=1, total=6, fmt="instagram", brand=brand), 1080, 1350))).convert("RGB")
    # the ring stripe is the last 14px of the slide; text overflowing the safe area would paint over the margins
    margin = img.crop((0, 1230, 1080, 1320))
    assert margin.getextrema()[0][0] > 150  # still (near) white: no black text bled into the bottom margin


def test_prepare_photo_rejects_garbage_and_downsizes():
    uri, w, h = prepare_photo(PNG)
    assert uri.startswith("data:image/jpeg;base64,") and (w, h) == (800, 450)
    with pytest.raises(SocialRenderError):
        prepare_photo(b"not an image")


# ───────────────────────── voice & video ─────────────────────────
def test_voice_track_keeps_segments_aligned():
    a, b = pcm_to_wav(b"\x01\x00" * 24000), pcm_to_wav(b"\x02\x00" * 12000)   # 1.0s and 0.5s at 24 kHz
    track = build_track([a, None, b], [2.0, 1.5, 1.0])
    assert wav_info(track)[1] == pytest.approx(4.5, abs=0.01)
    pcm = track[44:]
    assert pcm[: 2] == b"\x01\x00" and pcm[2 * 24000: 2 * 24000 + 2] == b"\x00\x00"        # silence after the first narration
    assert pcm[2 * 84000: 2 * 84000 + 2] == b"\x02\x00"                             # third segment starts at 3.5 s
    with pytest.raises(VoiceError):
        build_track([None], [1])
    with pytest.raises(VoiceError):
        build_track([a, pcm_to_wav(b"\x00\x00" * 100, rate=8000)], [1, 1])


def test_narrator_enabled_only_with_provider_and_key(settings):
    assert not Narrator(settings).enabled
    assert Narrator(replace(settings, social_voice="gemini")).enabled
    assert not Narrator(replace(settings, social_voice="openai", openai_api_key="")).enabled


@needs_ffmpeg
def test_video_is_vertical_h264_with_expected_length(tmp_path):
    from PIL import Image

    frames = []
    for c in ((20, 100, 80), (255, 87, 87), (40, 90, 160)):
        buf = io.BytesIO()
        Image.new("RGB", (1080, 1920), c).save(buf, "PNG")
        frames.append(buf.getvalue())
    audio = build_track([pcm_to_wav(b"\x10\x00" * 24000)], [1.0])
    f = tmp_path / "v.mp4"
    f.write_bytes(build_video(frames, [1.0, 1.0, 1.0], audio))
    info = subprocess.run([ffmpeg_path(), "-i", str(f)], capture_output=True, text=True).stderr
    assert "h264" in info and "1080x1920" in info and "aac" in info
    dur = [l for l in info.splitlines() if "Duration" in l][0].split("Duration:")[1].split(",")[0].strip()
    h, m, s = dur.split(":")
    assert float(s) == pytest.approx(3.0, abs=0.3)
    with pytest.raises(VideoError):
        build_video([], [])


# ───────────────────────── the designer ─────────────────────────
def make_ctx(**kw):
    base = dict(title="Aprueban nueva carretera en Lehigh Acres", excerpt="Obra vial aprobada.", body=BODY, fuente="WINK News",
                url="https://wink.example/road", keywords=["lehigh acres"], language="es", photo=PNG, photo_ai=True,
                post_id=501, post_link="http://x/?p=501", evidence=BODY)
    base.update(kw)
    return base and ArticleContext(**base)


def make_designer(settings, wp_server, scripts, events=None, **kw):
    llm = FakeLLM(settings, {"social": scripts})
    wp = WordPressClient(settings.wp_rest_url, settings.wp_auth_token)
    return SocialDesigner(settings, llm, FakeFetcher(), events or (lambda *a, **k: None), wp, **kw), llm


@pytest.fixture
def social_settings(settings, tmp_path):
    return replace(settings, social_enabled=True, social_video_seconds=20)


@needs_browser
@needs_ffmpeg
def test_designer_builds_the_full_kit_and_attaches_it(social_settings, wp_server, tmp_path):
    events = []
    d, llm = make_designer(social_settings, wp_server, [plan_json()], lambda a, t, m, **k: events.append((a, t)))
    kit = d.run(make_ctx(), out_root=tmp_path / "out")
    folder = Path(kit.folder)
    assert sorted(kit.files) == ["instagram", "tiktok", "video"]
    assert len(kit.files["instagram"]) == len(kit.files["tiktok"]) == 6
    for f in kit.files["instagram"] + kit.files["tiktok"] + kit.files["video"]:
        assert (folder / f).stat().st_size > 1000
    assert "#GoLehighAcres" in (folder / "captions.txt").read_text() and (folder / "plan.json").exists()
    assert kit.ai_image and kit.seconds > 8 and not kit.warnings
    # uploaded to the media library (2 carousels × 6 + video) and linked to the post through lnh_social
    uploads = [r for r in wp_server.requests if r["path"].endswith("/wp/v2/media") and r["method"] == "POST"]
    assert len(uploads) == 13 and uploads[-1]["headers"]["Content-Type"] == "video/mp4"
    post_id, payload = wp_server.post_updates[0]
    meta = json.loads(payload["meta"]["lnh_social"])
    assert post_id == 501 and meta["ai_image"] and len(meta["instagram"]["slides"]) == 6 and meta["video"]["name"] == "video.mp4"
    assert "#GoLehighAcres" in meta["hashtags"]
    assert ("social", "attached") in events and ("social", "video") in events


@needs_browser
def test_designer_without_video_or_upload_is_local_only(social_settings, wp_server, tmp_path):
    d, _ = make_designer(social_settings, wp_server, [plan_json()])
    kit = d.run(make_ctx(photo=b""), out_root=tmp_path, upload=False, video=False, formats=["instagram"])
    assert list(kit.files) == ["instagram"] and not kit.uploaded
    assert not [r for r in wp_server.requests if "/wp/v2/" in r["path"]]


@needs_browser
def test_designer_survives_failed_upload_and_voice(social_settings, wp_server, tmp_path, monkeypatch):
    class Boom(Narrator):
        enabled = True

        def speak(self, text):
            raise VoiceError("sin cuota")

    d, _ = make_designer(social_settings, wp_server, [plan_json()], narrator=Boom(replace(social_settings, social_voice="gemini")))
    d.wp.upload_media = lambda *a, **k: (_ for _ in ()).throw(RuntimeError("disk full"))
    kit = d.run(make_ctx(), out_root=tmp_path, formats=["instagram"], video=False)
    assert any("No se pudo subir" in w for w in kit.warnings) and kit.files["instagram"]
    assert not wp_server.post_updates      # nothing uploaded: an earlier good kit is never overwritten by an empty one
    assert any("se conserva" in w for w in kit.warnings)


def test_designer_repairs_unsupported_figures_then_gives_up(social_settings, wp_server, tmp_path):
    bad = plan_json()
    bad["slides"][3]["body"] = "Habrá 1,250 empleos nuevos."
    d, llm = make_designer(social_settings, wp_server, [bad, plan_json()])
    plan = d.make_plan(make_ctx())
    assert len(llm.calls) == 2 and "1,250" in llm.calls[1][2] and plan.slides[3].body == "Se prevé para 2027."
    d2, _ = make_designer(social_settings, wp_server, [bad])
    with pytest.raises(SocialError, match="1,250"):
        d2.make_plan(make_ctx())
    d3, _ = make_designer(social_settings, wp_server, ["no json"])
    with pytest.raises(LLMError):
        d3.make_plan(make_ctx())


def test_webhook_is_signed(social_settings, wp_server, tmp_path):
    sent = {}

    class Http:
        def post(self, url, data, headers, timeout):
            sent.update(url=url, data=data, headers=headers)
            return type("R", (), {"status_code": 200})()

    s = replace(social_settings, social_webhook_url="https://hooks.example/x", social_webhook_secret="s3cret")
    d, _ = make_designer(s, wp_server, [plan_json()], http=Http())
    from lehigh_agents.social.designer import SocialKit

    kit = SocialKit(slug="a", folder=str(tmp_path), plan=planmod.parse_plan(plan_json()).__dict__ | {"slides": [], "scenes": []},
                    files={})
    d._webhook(kit, make_ctx())
    expect = "sha256=" + hmac.new(b"s3cret", sent["data"], hashlib.sha256).hexdigest()
    assert sent["headers"]["X-Lehigh-Signature"] == expect and json.loads(sent["data"])["event"] == "social_kit_ready"


# ───────────────────────── pipeline integration ─────────────────────────
def _pipeline_parts(settings):
    import datetime as dt

    from conftest import article_page
    from lehigh_agents.llm import Grounded
    from test_agents import ARTICLE_JSON, FACTS, IMG_PROMPT, URL, FakeGen, audit_json

    TODAY = dt.date.today().isoformat()
    fetch = FakeFetcher({URL: article_page()})
    grounded = Grounded("n", ["q"], [{"uri": URL, "title": "w", "domain": "w"}])
    llm = FakeLLM(settings, {
        "rastreador": [[{"titulo_fuente": "Road", "url": URL, "resumen_hechos": FACTS, "fecha": TODAY, "palabras_clave": ["road"]}]],
        "redactor": [ARTICLE_JSON], "imagen": [{"image_prompt": IMG_PROMPT, "alt_text": "alt"}], "auditor": [audit_json(92)],
        "social": [plan_json()]}, grounded)
    return llm, fetch, FakeGen()


def test_pipeline_runs_agent4_after_approval_and_never_fails_the_article(social_settings, wp_server):
    from lehigh_agents.pipeline import Pipeline

    llm, fetch, gen = _pipeline_parts(social_settings)
    seen = {}

    class Fake:
        events = None

        def run(self, ctx):
            seen["ctx"] = ctx
            return type("K", (), {"folder": "/x", "files": {"instagram": ["a.png"]}, "seconds": 12.0, "voice": "", "uploaded": {},
                                  "warnings": [], "plan": {"hook": "h"}})()

    pipe = Pipeline(social_settings, llm=llm, wp=WordPressClient(social_settings.wp_rest_url, social_settings.wp_auth_token),
                    imagegen=gen, fetcher=fetch, social=Fake())
    item = pipe.run_once()["items"][0]
    assert item["posted"] and item["social"]["files"] == {"instagram": ["a.png"]}
    assert seen["ctx"].post_id == 501 and seen["ctx"].photo_ai and seen["ctx"].photo   # AI image bytes are handed over in memory
    assert "social" in json.loads(next(r for r in wp_server.requests if r["path"].endswith("/lnh/v1/runs"))["body"])["models"]

    llm, fetch, gen = _pipeline_parts(social_settings)

    class Broken(Fake):
        def run(self, ctx):
            raise SocialError("sin Chromium")

    s2 = replace(social_settings, state_dir=social_settings.state_dir / "second")   # fresh anti-duplicate memory
    pipe = Pipeline(s2, llm=llm, wp=WordPressClient(s2.wp_rest_url, s2.wp_auth_token), imagegen=gen, fetcher=fetch,
                    social=Broken())
    report = pipe.run_once()
    assert report["items"][0]["posted"] and report["items"][0]["social"] is None and report["counts"]["approved"] == 1
    assert any(e["agent"] == "social" and e["type"] == "error" for e in report["events"])


def test_pipeline_skips_agent4_when_disabled_or_flagged(settings, wp_server):
    from lehigh_agents.pipeline import Pipeline

    llm, fetch, gen = _pipeline_parts(settings)          # SOCIAL_ENABLED=false in the base fixture
    item = Pipeline(settings, llm=llm, wp=WordPressClient(settings.wp_rest_url, settings.wp_auth_token), imagegen=gen,
                    fetcher=fetch, social=object()).run_once()["items"][0]
    assert "social" not in item


# ───────────────────────── CLI ─────────────────────────
@needs_browser
def test_cli_social_command_designs_from_an_existing_post(settings, wp_server, tmp_path, monkeypatch, capsys):
    from lehigh_agents import cli

    monkeypatch.setenv("SOCIAL_ENABLED", "true")
    monkeypatch.setattr(cli, "LLM", lambda s: FakeLLM(s, {"social": [plan_json()]}))
    out = tmp_path / "kit"
    rc = cli.main(["social", "--post", "501", "--no-video", "--formats", "instagram", "--dry-run", "--out", str(out)])
    text = capsys.readouterr().out
    assert rc == 0 and "instagram: 6" in text and "video" not in text.split("✓")[1]
    folder = next(out.iterdir())
    assert len(list(folder.glob("instagram-*.png"))) == 6 and not list(folder.glob("tiktok-*"))
    assert not wp_server.post_updates                      # --dry-run: nothing written back
    rc = cli.main(["social", "--latest", "1", "--no-video", "--formats", "tiktok", "--out", str(out)])
    assert rc == 0 and wp_server.post_updates and wp_server.post_updates[0][0] == 501


# ───────────────────────── review fixes ─────────────────────────
@needs_browser
def test_short_headlines_keep_their_full_size(settings):
    brand = load_brand(settings)
    with SlideRenderer() as r:
        sizes = {}
        for kind, size in (("point", 70), ("cover", 100)):
            r.png(slide_html(planmod.Slide(kind, "Plan de drenaje aprobado", "Cuerpo corto"), index=1, total=6, fmt="instagram",
                             brand=brand), 1080, 1350)
            sizes[kind] = r._page.evaluate("() => parseFloat(getComputedStyle(document.querySelector('.h')).fontSize)")
        assert sizes == {"point": 70, "cover": 100}
        r.png(slide_html(planmod.Slide("point", "muy largo " * 30, ""), index=1, total=6, fmt="instagram", brand=brand), 1080, 1350)
        assert r._page.evaluate("() => parseFloat(getComputedStyle(document.querySelector('.h')).fontSize)") < 70   # still shrinks


def test_every_video_scene_with_a_photo_carries_the_ai_label(settings):
    brand = load_brand(settings)
    for i in range(3):
        html = scene_html("Texto", index=i, total=4, brand=brand, photo="data:image/jpeg;base64,AAA", ai_label="Ilustración generada con IA")
        assert "Ilustración generada con IA" in html
    assert "Ilustración" not in scene_html("Texto", index=1, total=4, brand=brand, ai_label="Ilustración generada con IA")  # no photo, no label


def test_copy_guard_covers_alt_text_and_hashtags():
    d = plan_json(alt_text="Foto de 850 personas en el evento", hashtags=["Lehigh33972", "Top500"])
    problems = planmod.semantic_problems(planmod.parse_plan(d), BODY)
    assert problems and "850" in problems


def test_ffmpeg_dying_silently_is_a_video_error_not_a_crash(monkeypatch):
    import subprocess

    from lehigh_agents.social import video

    monkeypatch.setattr(video.subprocess, "run", lambda *a, **k: subprocess.CompletedProcess(a, 137, b"", b""))
    with pytest.raises(VideoError, match="137"):
        video._run(["ffmpeg"])
    def boom(*a, **k):
        raise FileNotFoundError("nope")
    monkeypatch.setattr(video.subprocess, "run", boom)
    with pytest.raises(VideoError, match="ffmpeg"):
        video._run(["ffmpeg"])


@needs_browser
def test_dry_run_never_fires_the_webhook(social_settings, wp_server, tmp_path):
    calls = []

    class Http:
        def post(self, *a, **k):
            calls.append(a)
            return type("R", (), {"status_code": 200})()

    s = replace(social_settings, social_webhook_url="https://hooks.example/x")
    d, _ = make_designer(s, wp_server, [plan_json()], http=Http())
    d.run(make_ctx(), out_root=tmp_path, upload=False, video=False, formats=["instagram"])
    assert calls == []
    assert "folder" not in json.dumps(__import__("lehigh_agents.social.designer", fromlist=["SocialKit"]).SocialKit(
        slug="a", folder="/secret/path", plan=planmod.parse_plan(plan_json()).__dict__ | {"slides": [], "scenes": []}, files={}).to_meta())


def test_social_model_without_its_key_is_reported(settings):
    s = replace(settings, social_enabled=True, social_model="claude-3-5-haiku-latest", anthropic_api_key="")
    assert any("SOCIAL_MODEL" in p for p in s.problems(need_wordpress=False))
    assert not any("SOCIAL_MODEL" in p for p in replace(s, social_enabled=False).problems(need_wordpress=False))
