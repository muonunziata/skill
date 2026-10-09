#!/usr/bin/env python3
"""List every translatable string of the plugin (singular and plural) in source order."""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / "lehigh-news-hub"
STR = r"""(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")"""
SIMPLE = re.compile(r"\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*" + STR + r"\s*,\s*'lehigh-news-hub'\s*\)")
PLURAL = re.compile(r"\b_n\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,\s*[^,]+,\s*'lehigh-news-hub'\s*\)")


def unq(a, b):
    if a is not None:  # PHP single quotes: only \\ and \' are escapes
        return a.replace("\\'", "'").replace("\\\\", "\\")
    # PHP double quotes: decode the common escapes
    out = re.sub(r"\\([ntr\\$\"])", lambda m: {"n": "\n", "t": "\t", "r": "\r", "\\": "\\", "$": "$", '"': '"'}[m.group(1)], b)
    return out


def extract():
    out = {}  # (msgid, plural) -> first location
    for f in sorted(ROOT.rglob("*.php")):
        text = f.read_text()
        for m in SIMPLE.finditer(text):
            key = (unq(m.group(1), m.group(2)), None)
            out.setdefault(key, f"{f.relative_to(ROOT)}:{text[:m.start()].count(chr(10)) + 1}")
        for m in PLURAL.finditer(text):
            key = (unq(m.group(1), m.group(2)), unq(m.group(3), m.group(4)))
            out.setdefault(key, f"{f.relative_to(ROOT)}:{text[:m.start()].count(chr(10)) + 1}")
    return out


if __name__ == "__main__":
    strings = extract()
    for (sing, plur), loc in strings.items():
        print(f"{loc}\t{sing!r}" + (f" | {plur!r}" if plur else ""))
    print(len(strings), "strings", file=sys.stderr)
