"""HTTP fetching with SSRF protection.

URLs come from a language model and from web pages, so they are untrusted: only http(s), only public
IP addresses, redirects re-validated hop by hop, bounded size and time.
"""
from __future__ import annotations

import ipaddress
import socket
import urllib.error
import urllib.request
from dataclasses import dataclass
from urllib.parse import urlsplit

USER_AGENT = "LehighNewsHubBot/1.0 (+local news research; respects robots on request)"


class FetchError(Exception):
    def __init__(self, message: str, status: int = 0):
        super().__init__(message)
        self.status = status  # HTTP status when the server answered, 0 for network / policy errors


@dataclass
class FetchResult:
    url: str
    status: int
    content_type: str
    body: bytes
    truncated: bool = False

    def text(self) -> str:
        charset = "utf-8"
        if "charset=" in self.content_type:
            charset = self.content_type.split("charset=")[-1].split(";")[0].strip() or "utf-8"
        try:
            return self.body.decode(charset, errors="replace")
        except LookupError:
            return self.body.decode("utf-8", errors="replace")


def check_public_url(url: str, allow_private: bool = False) -> None:
    parts = urlsplit(url)
    if parts.scheme not in ("http", "https"):
        raise FetchError(f"blocked scheme: {parts.scheme or 'none'}")
    host = parts.hostname
    if not host:
        raise FetchError("URL has no host")
    if allow_private:
        return
    try:
        infos = socket.getaddrinfo(host, parts.port or (443 if parts.scheme == "https" else 80), type=socket.SOCK_STREAM)
    except socket.gaierror as exc:
        raise FetchError(f"cannot resolve {host}: {exc}") from exc
    for info in infos:
        ip = ipaddress.ip_address(info[4][0])
        if not ip.is_global:
            raise FetchError(f"blocked non-public address {ip} for {host}")


class _Redirects(urllib.request.HTTPRedirectHandler):
    def __init__(self, allow_private: bool, limit: int = 5):
        self.allow_private = allow_private
        self.limit = limit
        self.count = 0

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401
        self.count += 1
        if self.count > self.limit:
            raise FetchError("too many redirects")
        check_public_url(newurl, self.allow_private)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class Fetcher:
    def __init__(self, timeout: float = 15.0, max_bytes: int = 2_000_000, allow_private: bool = False):
        self.timeout = timeout
        self.max_bytes = max_bytes
        self.allow_private = allow_private

    def get(self, url: str, max_bytes: int | None = None, accept: str = "*/*") -> FetchResult:
        check_public_url(url, self.allow_private)
        limit = max_bytes or self.max_bytes
        opener = urllib.request.build_opener(_Redirects(self.allow_private))
        req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": accept})
        try:
            with opener.open(req, timeout=self.timeout) as resp:
                body = resp.read(limit + 1)
                truncated = len(body) > limit
                return FetchResult(
                    url=resp.geturl(),
                    status=resp.status,
                    content_type=resp.headers.get("Content-Type", ""),
                    body=body[:limit],
                    truncated=truncated,
                )
        except urllib.error.HTTPError as exc:
            raise FetchError(f"HTTP {exc.code} for {url}", exc.code) from exc
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            raise FetchError(f"network error for {url}: {exc}") from exc
