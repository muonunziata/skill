=== Lehigh News Hub ===
Contributors: lehighnewshub
Tags: ai, news, editorial workflow, shortcode, latest posts
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Editorial hub for AI news agents: review queue with audit scores, a live view of what each agent did, one-click publishing and a fully configurable latest-news shortcode.

== Description ==

Lehigh News Hub receives the articles written by an external team of AI agents (Researcher, Writer, Auditor) as ordinary WordPress drafts, together with the audit that was performed on them, and gives editors a professional place to decide what gets published.

* **Review queue** – every agent article with its audit score, flags, source and featured image. Publish, schedule, reject or restore, one by one or in bulk.
* **Article review screen** – preview, search-result preview, auditor notes, automatic checks (broken links, unsafe HTML, unsupported figures, copied text), source facts, the image prompt, and the agents' step-by-step timeline.
* **Agent activity** – cards per agent, a seven-day pipeline funnel, a live feed, run history with token usage, and a detail page per run.
* **Optional auto-publish** for approved articles above a score you choose; email notifications for new drafts.
* **Transparency** – source line and AI disclosure on agent articles; AI-generated featured images are always labelled.
* **Shortcode `[lehigh_news]`** with five layouts, 40+ options, presets, caching, pagination, light/dark/auto themes and a live **shortcode builder**.
* English and Spanish interface.

= Shortcode examples =

`[lehigh_news]`
`[lehigh_news layout="featured" count="5" heading="Latest news" more_link="/news"]`
`[lehigh_news layout="compact" category="community" since="7 days" min_score="85" show_source="1"]`
`[lehigh_news preset="homepage"]`

== Installation ==

1. Upload the plugin and activate it. It creates the pages it needs (News, How we work, Corrections & contact) and takes you to the hub.
2. Create an Application Password for the account the agents use (Users → Profile). An Author or Editor account is recommended.
3. Put `WP_REST_URL` and `WP_AUTH_TOKEN` (`user:application password`) in the agents' `.env`.
4. Open **News Hub** in the admin menu.

== Frequently Asked Questions ==

= Does it publish anything by itself? =
No, unless you enable auto-publish in Settings. Agents create drafts; flagged articles are never auto-published.

= Where do the audit data live? =
In post meta keys starting with `lnh_`. They are readable through the REST API only by people who can edit the post (visitors never see them) and they survive uninstalling the plugin.

= Is the HTML sent by the agents trusted? =
No. It is filtered with `wp_kses_post` on arrival, regardless of the account's capabilities.

== Changelog ==

= 1.5.4 =
* Fixes “Gemini model gemini-2.5-flash was not found”: Google retires model names every few months, so the agents now default to `auto` (the newest Gemini Flash the API serves) and, if a pinned name disappears, switch by themselves to a served model (text, image and voice models) and say so in the log. The downloaded package is configured with `auto`.

= 1.5.3 =
* Accepts Google's new Gemini key format (AQ.…, issued by AI Studio since the key change) as well as the old AIza… keys; the field and messages now show the new format.

= 1.5.2 =
* The agents' installer now shows a live progress meter (overall percentage, current step, elapsed time, a moving spinner and “no news for N s” warnings) so you can tell a slow download from a stuck one. A full log is written to instalacion.log.
* Fixes the setup wizard stopping with “client has been closed” while checking the Gemini key, keeps the saved key on network problems, and shows pasted keys on Windows (hidden prompts cannot be pasted into). The installer skips the wizard when the downloaded package is already configured.

= 1.5.1 =
* Works on sites without Application Passwords (plain HTTP, or disabled by a security plugin): the plugin now creates its own private connection key for the agents. It is stored only as a hash, works only on REST API requests and only for the agents' Author account, and generating a new one revokes the previous.

= 1.5.0 =
* Set up the agents in 3 steps: paste a free Gemini key, press “Download my agents” and double-click INICIAR (Windows / Mac). The downloaded package already contains the site address, a private connection password and the key, and installs everything it needs on first run. The manual flow remains under “Advanced”.

= 1.4.1 =
* “Working” animation on the control card: the four agents as a pipeline, the busy one wrapped in the logo's colour ring, finished ones ticked, data packets flowing between them, and a breathing idle state. Updates live and respects reduced-motion settings.

= 1.4.0 =
* Start / Pause button: control the agents from the dashboard and the activity screen. The agents (`python main.py watch`) connect to the hub by themselves, report what they are doing live and obey Start, Pause (they finish the article in progress first) and Run now. Nothing runs until you press Start.
* New “Run every (minutes)” setting is sent to the connected agents.

= 1.3.0 =
* New Agent 4, the Social designer: each approved article gets an Instagram carousel, a TikTok carousel and a vertical video on the GoLehighAcres.org brand, with captions and hashtags, shown in a “Social kit” card on the review screen (preview, download, copy).
* The social designer appears in the agent panel, the activity feed and the dashboard.
* GoLehighAcres.org logo in the admin screens and brand green as the default accent colour.

= 1.2.0 =
* One-click agent credentials: creates a dedicated Author account and an Application Password and shows a ready-to-use .env block (copy or download). Nothing is stored in plain text.
* Works with the new `python main.py setup` wizard in the agents package.

= 1.1.0 =
* Creates the pages the site needs on activation: news listing, how we work (AI transparency) and corrections & contact. Never duplicated or overwritten; recreate missing ones from Settings → Pages.
* Creates a default News category, a welcome screen and a one-time redirect to the hub after activation.
* Optional public contact email shown by [lehigh_news_contact].

= 1.0.0 =
* First release.
