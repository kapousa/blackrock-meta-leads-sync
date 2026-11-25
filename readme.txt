=== Black Rock Meta Leads Sync ===
Contributors: croconilesoftware
Requires at least: 5.8
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later

Receives Facebook/Instagram (Meta) Lead Ads webhooks and creates leads in the Black Rock CRM ("leads" post type).

== Description ==
Developed by CrocoNile Software (https://croconile.site).

Webhook URL: https://your-site/wp-json/blackrock/v1/meta-webhook
Configure under Settings > Meta Leads Sync.

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/ and activate it.
2. Go to Settings > Meta Leads Sync and enter the verify token, App Secret and Page Access Token.
3. In Meta App Dashboard > Webhooks > Page, add the callback URL and verify token, then subscribe to "leadgen".

== Changelog ==
= 1.0.1 =
* Added automatic update checks from GitHub releases.

= 1.0.0 =
* Initial release.
