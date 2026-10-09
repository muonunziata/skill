"""WordPress REST client (core endpoints + the Lehigh News Hub plugin's /lnh/v1 endpoints)."""
from __future__ import annotations

import base64
import logging
import re
from typing import Any
from urllib.parse import urlsplit

import requests

from . import __version__

log = logging.getLogger("lehigh.wp")


class WPError(Exception):
    def __init__(self, message: str, status: int = 0):
        super().__init__(message)
        self.status = status


def normalize_root(url: str) -> str:
    """Accept a site URL, a /wp-json root or a full /wp/v2/posts URL and return the REST root."""
    u = url.strip().rstrip("/")
    u = re.sub(r"/wp/v2(/.*)?$", "", u)
    if "/wp-json" in u:
        return u[: u.index("/wp-json") + len("/wp-json")]
    return u + "/wp-json"


class WordPressClient:
    def __init__(self, rest_url: str, token: str, timeout: float = 45, session: Any = None):
        if not rest_url or not token:
            raise WPError("WP_REST_URL and WP_AUTH_TOKEN are required")
        self.root = normalize_root(rest_url)
        parts = urlsplit(self.root)
        self.site = f"{parts.scheme}://{parts.netloc}{parts.path.removesuffix('/wp-json')}"
        self.timeout = timeout
        self.http = session or requests.Session()
        token = token.strip()
        if token.lower().startswith("bearer "):
            auth = token
        elif ":" in token:  # "user:application password"
            auth = "Basic " + base64.b64encode(token.encode()).decode()
        else:
            auth = "Bearer " + token
        self.headers = {"Authorization": auth, "Accept": "application/json",
                        "User-Agent": f"lehigh-agents/{__version__}"}
        self._tag_cache: dict[str, int] = {}

    # ───────────────────────── low level ─────────────────────────
    def _req(self, method: str, path: str, **kw: Any) -> Any:
        url = self.root + path
        headers = {**self.headers, **kw.pop("headers", {})}
        timeout = kw.pop("timeout", self.timeout)
        try:
            r = self.http.request(method, url, headers=headers, timeout=timeout, **kw)
        except requests.RequestException as exc:
            raise WPError(f"{method} {path} failed: {exc}") from exc
        if r.status_code >= 400:
            try:
                detail = r.json().get("message", r.text)
            except ValueError:
                detail = r.text
            raise WPError(f"{method} {path} -> HTTP {r.status_code}: {str(detail)[:300]}", r.status_code)
        try:
            return r.json()
        except ValueError:
            return {}

    # ───────────────────────── diagnostics ─────────────────────────
    def whoami(self) -> dict[str, Any]:
        return self._req("GET", "/wp/v2/users/me?context=edit")

    def hub_ping(self) -> dict[str, Any] | None:
        """Plugin presence check (returns None when the Lehigh News Hub plugin is not active)."""
        try:
            return self._req("GET", "/lnh/v1/ping")
        except WPError as exc:
            if exc.status in (404, 401, 403):
                return None
            raise

    # ───────────────────────── media ─────────────────────────
    def upload_media(self, data: bytes, filename: str, mime: str, alt: str = "", caption: str = "",
                     title: str = "", timeout: float | None = None) -> dict[str, Any]:
        safe = re.sub(r"[^A-Za-z0-9._-]", "-", filename)
        extra = {"timeout": timeout} if timeout else {}
        created = self._req("POST", "/wp/v2/media", data=data, headers={
            "Content-Disposition": f'attachment; filename="{safe}"', "Content-Type": mime}, **extra)
        media_id = int(created["id"])
        fields = {k: v for k, v in {"alt_text": alt, "caption": caption, "title": title}.items() if v}
        if fields:
            try:
                created = self._req("POST", f"/wp/v2/media/{media_id}", json=fields)
            except WPError as exc:  # the file is uploaded; metadata is a nicety
                log.warning("media %s uploaded but metadata update failed: %s", media_id, exc)
        return {"id": media_id, "source_url": created.get("source_url", ""), "link": created.get("link", "")}

    def delete_media(self, media_id: int) -> bool:
        try:
            self._req("DELETE", f"/wp/v2/media/{int(media_id)}?force=true")
            return True
        except WPError as exc:
            log.warning("could not delete media %s: %s", media_id, exc)
            return False

    # ───────────────────────── tags / posts ─────────────────────────
    def resolve_tags(self, names: list[str]) -> list[int]:
        ids: list[int] = []
        for name in names:
            key = name.strip().lower()
            if not key:
                continue
            if key in self._tag_cache:
                ids.append(self._tag_cache[key])
                continue
            try:
                found = self._req("GET", "/wp/v2/tags", params={"search": name, "per_page": 20})
                match = next((t for t in found if t.get("name", "").lower() == key), None)
                tag = match or self._req("POST", "/wp/v2/tags", json={"name": name})
                self._tag_cache[key] = int(tag["id"])
                ids.append(int(tag["id"]))
            except (WPError, KeyError, TypeError) as exc:
                log.warning("tag '%s' skipped: %s", name, exc)
        return ids

    def create_post(self, payload: dict[str, Any]) -> dict[str, Any]:
        post = self._req("POST", "/wp/v2/posts", json=payload)
        pid = int(post["id"])
        return {"id": pid, "link": post.get("link", ""), "status": post.get("status", ""),
                "edit_link": f"{self.site}/wp-admin/post.php?post={pid}&action=edit"}

    def get_post(self, post_id: int) -> dict[str, Any]:
        return self._req("GET", f"/wp/v2/posts/{int(post_id)}?context=edit")

    def latest_posts(self, count: int = 1, statuses: str = "draft,pending,publish") -> list[dict[str, Any]]:
        """Newest articles that the agents created (they carry lnh_* meta)."""
        found = self._req("GET", "/wp/v2/posts", params={"per_page": 50, "status": statuses, "context": "edit",
                                                        "orderby": "date", "order": "desc"})
        return [p for p in found if (p.get("meta") or {}).get("lnh_source_url")][:count]

    def update_post(self, post_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return self._req("POST", f"/wp/v2/posts/{int(post_id)}", json=payload)

    # ───────────────────────── plugin: activity log ─────────────────────────
    def post_run_report(self, report: dict[str, Any]) -> bool:
        try:
            self._req("POST", "/lnh/v1/runs", json=report)
            return True
        except WPError as exc:
            log.info("run report not stored (plugin missing or not authorised): %s", exc)
            return False
