#!/usr/bin/env python3
"""Generate languages/*.pot, es_ES.po and a compiled es_ES.mo from the plugin source and translations_es.py.

  python3 wordpress/tools/build_i18n.py

Verifies that every string is translated and that printf-style and {variable} placeholders match.
"""
import re
import struct
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import extract_strings  # noqa: E402
from translations_es import T  # noqa: E402

LANG_DIR = extract_strings.ROOT / "languages"
DOMAIN = "lehigh-news-hub"
VERSION = "1.0.0"
PLACEHOLDER = re.compile(r"%(?:\d+\$)?[sd]|\{[a-z_]+\}|%%")


def esc(s: str) -> str:
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n").replace("\t", "\\t")


def header(lang: str | None) -> str:
    lines = [
        f"Project-Id-Version: Lehigh News Hub {VERSION}",
        "Report-Msgid-Bugs-To: https://github.com/muonunziata/skill/issues",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
    ]
    if lang:
        lines += [f"Language: {lang}", "Plural-Forms: nplurals=2; plural=(n != 1);"]
    return "".join(f"{l}\\n" for l in lines)


def write_po(path: Path, strings: dict, lang: str | None):
    out = [f'msgid ""\nmsgstr ""\n"' + header(lang).replace("\\n", '\\n"\n"') + '"\n']
    for (sing, plur), loc in strings.items():
        out.append(f"#: {loc}")
        out.append(f'msgid "{esc(sing)}"')
        if plur:
            out.append(f'msgid_plural "{esc(plur)}"')
            if lang:
                t0, t1 = T[(sing, plur)]
                out += [f'msgstr[0] "{esc(t0)}"', f'msgstr[1] "{esc(t1)}"']
            else:
                out += ['msgstr[0] ""', 'msgstr[1] ""']
        else:
            out.append(f'msgstr "{esc(T[sing]) if lang else ""}"')
        out.append("")
    path.write_text("\n".join(out), encoding="utf-8")


def write_mo(path: Path, strings: dict):
    entries = {"": header("es_ES").replace("\\n", "\n")}
    for (sing, plur), _ in strings.items():
        if plur:
            entries[f"{sing}\0{plur}"] = "\0".join(T[(sing, plur)])
        else:
            entries[sing] = T[sing]
    keys = sorted(entries)
    ids = b"".join(k.encode() + b"\0" for k in keys)
    vals = b"".join(entries[k].encode() + b"\0" for k in keys)
    n = len(keys)
    id_off, val_off = 28 + n * 16, 28 + n * 16 + len(ids)
    table_ids, table_vals, o1, o2 = [], [], 0, 0
    for k in keys:
        lk, lv = len(k.encode()), len(entries[k].encode())
        table_ids.append((lk, id_off + o1))
        table_vals.append((lv, val_off + o2))
        o1 += lk + 1
        o2 += lv + 1
    blob = struct.pack("<7I", 0x950412DE, 0, n, 28, 28 + n * 8, 0, 0)
    blob += b"".join(struct.pack("<II", *t) for t in table_ids) + b"".join(struct.pack("<II", *t) for t in table_vals)
    path.write_bytes(blob + ids + vals)


def main() -> int:
    strings = extract_strings.extract()
    problems = []
    for (sing, plur), loc in strings.items():
        key = (sing, plur) if plur else sing
        if key not in T:
            problems.append(f"untranslated: {sing!r} ({loc})")
            continue
        tr = T[key] if plur else (T[key],)
        for t in tr:
            if sorted(PLACEHOLDER.findall(sing)) != sorted(PLACEHOLDER.findall(t)) and not (plur and PLACEHOLDER.findall(sing) and not PLACEHOLDER.findall(t)):
                problems.append(f"placeholder mismatch: {sing!r} -> {t!r}")
    if problems:
        print("\n".join(problems), file=sys.stderr)
        return 1
    LANG_DIR.mkdir(exist_ok=True)
    write_po(LANG_DIR / f"{DOMAIN}.pot", strings, None)
    write_po(LANG_DIR / f"{DOMAIN}-es_ES.po", strings, "es_ES")
    write_mo(LANG_DIR / f"{DOMAIN}-es_ES.mo", strings)
    print(f"{len(strings)} strings -> {LANG_DIR}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
