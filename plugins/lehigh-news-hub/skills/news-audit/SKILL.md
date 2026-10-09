---
name: news-audit
description: Manually audit a local-news draft or social copy against its source - facts, figures, attribution, links, HTML safety, copy overlap, tone and legal risk - using the same checks as the Auditor agent. Use when asked to review, fact-check or QA an article, an agent run, or a social kit before it is published.
---

# News audit checklist (mirrors `agents/auditor.py` + `checks.py`)

Inputs: the article (HTML/Markdown or WordPress post), the finding (`resumen_hechos`, source URL) and, when possible, the source page text.

1. **Facts** - every claim traceable to the finding or source text. List any claim you cannot trace as *unsupported*. Dates, names, places, amounts.
2. **Figures** - each number appears in the source (accept equivalent spellings: 4.5 = 4,5; 1,200 = 1.200). Years and 1-digit numbers are ignored. Invented figures are critical.
3. **Attribution** - source named in the text, no rumours presented as fact, accusations only as allegations, quotes verbatim.
4. **Links** - open every URL (`checks.check_links`): broken = penalty; unverifiable = note only.
5. **HTML safety** - no `<script>`, inline handlers, `javascript:` URLs, iframes other than verified YouTube; WordPress shortcodes must be neutralised.
6. **Originality** - literal overlap with the source (8-word sequences): above 30% fails the audit; aim for well under 15%.
7. **Language & tone** - correct Spanish (or configured language), neutral, no sensationalism, local relevance to Lehigh Acres.
8. **Risk** - defamation, minors, victims, private data, graphic detail. Sensitive topics must use neutral imagery.
9. **Images** - AI image labelled, alt text present, no real brands/logos/insignia/identifiable people.
10. **Social copy** (if present) - same fact rules; hook is true; captions do not add claims; hashtags relevant; brand rules from `brand-guide`.

## Output
```json
{"audit_score": 0-100, "status": "approved|flagged", "audit_notes": ["concrete, actionable note", "..."]}
```
Approve only if there are no unsupported facts/figures, no broken critical links and no legal/safety risk (`AUDIT_MIN_SCORE`, default 80). Be specific: quote the sentence and say what to change. Do not rewrite the article unless asked.
To run the real checks on an existing draft use the Python helpers: `from lehigh_agents.checks import unsupported_numbers, source_overlap, unsafe_html_problems`.
