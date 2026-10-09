"""The Claude Code package (plugin, skills, subagents, marketplace) must stay consistent with the repo."""
import filecmp
import json
import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
PLUGIN = ROOT / "plugins" / "lehigh-news-hub"


def frontmatter(path: Path) -> dict:
    m = re.match(r"---\n(.*?)\n---\n", path.read_text(encoding="utf-8"), re.S)
    assert m, f"{path} has no frontmatter"
    out, key = {}, None
    for line in m.group(1).splitlines():
        if re.match(r"^\s+- ", line) and key:
            out.setdefault(key, []).append(line.split("- ", 1)[1].strip())
        elif ":" in line:
            key, _, val = line.partition(":")
            key = key.strip()
            out[key] = val.strip().strip('"') or []
    return out


def skills():
    return sorted(p for p in (PLUGIN / "skills").iterdir() if p.is_dir())


def agent_files():
    return sorted((PLUGIN / "agents").glob("*.md"))


def test_skills_and_agents_are_well_formed():
    assert {p.name for p in skills()} >= {"lehigh-news-hub", "brand-guide", "social-carousel", "social-video", "news-audit",
                                          "run-pipeline", "social-kit", "check-setup"}
    for d in skills():
        fm = frontmatter(d / "SKILL.md")
        assert fm["name"] == d.name and len(fm["description"]) > 40, d
    assert {f.stem for f in agent_files()} == {"news-researcher", "news-writer", "news-auditor", "social-designer"}
    known = {p.name for p in skills()}
    for f in agent_files():
        fm = frontmatter(f)
        assert fm["name"] == f.stem and len(fm["description"]) > 40
        assert set(fm.get("skills", [])) <= known, f"{f.name} preloads an unknown skill"


def test_side_effect_skills_are_manual_only():
    for name in ("run-pipeline", "social-kit"):
        assert frontmatter(PLUGIN / "skills" / name / "SKILL.md").get("disable-model-invocation") == "true"


def _same(a: Path, b: Path) -> bool:
    cmp = filecmp.dircmp(a, b)
    if cmp.left_only or cmp.right_only or cmp.diff_files or cmp.funny_files:
        return False
    return all(_same(a / d, b / d) for d in cmp.common_dirs)


def test_project_claude_folder_matches_the_plugin():
    assert _same(PLUGIN / "skills", ROOT / ".claude" / "skills"), "run scripts/sync-claude.sh"
    assert _same(PLUGIN / "agents", ROOT / ".claude" / "agents"), "run scripts/sync-claude.sh"


def test_marketplace_points_at_the_plugin_and_versions_agree():
    mp = json.loads((ROOT / ".claude-plugin" / "marketplace.json").read_text())
    plugin = json.loads((PLUGIN / ".claude-plugin" / "plugin.json").read_text())
    entry = mp["plugins"][0]
    assert entry["name"] == plugin["name"] == "lehigh-news-hub"
    assert (ROOT / entry["source"] / ".claude-plugin" / "plugin.json").is_file()
    assert mp["name"] not in {"claude-code-marketplace", "claude-code-plugins", "claude-plugins-official", "anthropic-marketplace"}
    php = (ROOT / "wordpress/lehigh-news-hub/lehigh-news-hub.php").read_text()
    wp_version = re.search(r"Version:\s*([\d.]+)", php).group(1)
    from lehigh_agents import __version__

    assert plugin["version"] == entry["version"] == wp_version == __version__
    assert f"Stable tag: {wp_version}" in (ROOT / "wordpress/lehigh-news-hub/readme.txt").read_text()


def test_skills_mention_only_real_commands_and_files():
    text = "\n".join(p.read_text() for p in PLUGIN.rglob("*.md"))
    for rel in ("agents/lehigh_agents/social/templates.py", "agents/lehigh_agents/prompts.py", "brand/golehighacres-logo-horizontal.png",
                "agents/lehigh_agents/agents/rastreador.py", "agents/lehigh_agents/pipeline.py"):
        if rel in text:
            assert (ROOT / rel).is_file(), rel
    for sub in ("check", "setup", "run", "watch", "social"):
        assert f"python main.py {sub}" in text or sub == "watch"
