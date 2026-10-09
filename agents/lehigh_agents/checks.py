"""Deterministic audit checks (no model involved): broken links, unsafe HTML, unsupported figures, copying."""
from __future__ import annotations

import re
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass

from .htmlutil import extract_urls, strip_tags, unsafe_html_problems, word_count
from .net import FetchError, Fetcher

# Statuses that mean "the site answered but blocks bots": we cannot tell, so we do not call the link broken.
UNVERIFIABLE = {401, 403, 405, 406, 429, 451, 999}


@dataclass
class LinkResult:
    url: str
    state: str  # ok | broken | unverifiable
    detail: str = ""


def _probe(fetcher: Fetcher, url: str) -> LinkResult:
    try:
        fetcher.get(url, max_bytes=2048)
        return LinkResult(url, "ok")
    except FetchError as exc:
        if exc.status in UNVERIFIABLE:
            return LinkResult(url, "unverifiable", f"HTTP {exc.status}")
        if exc.status >= 500:
            return LinkResult(url, "unverifiable", f"HTTP {exc.status} (server error, retry later)")
        if exc.inconclusive:  # timeout / refused by our SSRF policy: we cannot say the link is dead
            return LinkResult(url, "unverifiable", str(exc)[:160])
        return LinkResult(url, "broken", str(exc)[:160])


def check_links(fetcher: Fetcher, urls: list[str], limit: int = 15, workers: int = 5) -> list[LinkResult]:
    batch = urls[:limit]
    if len(batch) < 2:
        return [_probe(fetcher, u) for u in batch]
    with ThreadPoolExecutor(max_workers=min(workers, len(batch))) as pool:  # independent requests; map keeps the order
        return list(pool.map(lambda u: _probe(fetcher, u), batch))


_NUM = re.compile(r"(?<![\w.,])\$?\d[\d.,]*\d%?|(?<![\w.,])\$?\d%?")


def _forms(token: str) -> set[str]:
    """Equivalent spellings of one figure: 4.5 = 4,5 and 1,200 = 1.200 = 1200 (but 4,5 is never 45)."""
    t = re.sub(r"[^\d.,]", "", token).strip(".,")
    forms = {t}
    thousands_comma = re.fullmatch(r"\d{1,3}(,\d{3})+", t)
    thousands_dot = re.fullmatch(r"\d{1,3}(\.\d{3})+", t)
    if "," in t and "." not in t:
        forms.add(t.replace(",", "") if thousands_comma else t.replace(",", "."))
        if thousands_comma and t.count(",") == 1:
            forms.add(t.replace(",", "."))  # "1,200" may also be a European decimal
    elif "." in t and "," not in t:
        if thousands_dot:
            forms.add(t.replace(".", ""))
            if t.count(".") == 1:
                forms.add(t)  # "1.200" may also be a decimal
    elif "," in t and "." in t:
        if t.rfind(",") > t.rfind("."):  # 1.234,5 (European)
            forms.add(t.replace(".", "").replace(",", "."))
        else:  # 1,234.5 (English)
            forms.add(t.replace(",", ""))
    return forms


def _significant(token: str) -> bool:
    digits = re.sub(r"\D", "", token)
    return len(digits) >= 2 and not re.fullmatch(r"(19|20)\d\d", digits)  # bare years are checked by the model


def unsupported_numbers(article_text: str, evidence: str) -> list[str]:
    """Figures in the article that appear nowhere in the evidence (facts + source text)."""
    known: set[str] = set()
    for m in _NUM.findall(evidence):
        known |= _forms(m)
    out: list[str] = []
    for m in _NUM.findall(article_text):
        if _significant(m) and not (_forms(m) & known) and m not in out:
            out.append(m)
    return out[:8]


def source_overlap(article_text: str, source_text: str, n: int = 8) -> float:
    """Share of the article's n-word sequences that also occur verbatim in the source."""
    def grams(t: str) -> list[str]:
        w = re.findall(r"\w+", t.lower())
        return [" ".join(w[i : i + n]) for i in range(max(0, len(w) - n + 1))]

    a = grams(article_text)
    if not a or not source_text:
        return 0.0
    src = set(grams(source_text))
    return sum(1 for g in a if g in src) / len(a)


__all__ = ["check_links", "unsupported_numbers", "source_overlap", "extract_urls", "strip_tags",
           "unsafe_html_problems", "word_count", "LinkResult"]
